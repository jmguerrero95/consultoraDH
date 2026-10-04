<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Periods\MonthlyPeriod;

/**
 * Configuration refused: a rate or a cutoff rule that cannot be written as asked.
 *
 * ## Why this exists separately from the validation rules
 *
 * `StoreRateRequest` and `StoreCutoffRuleRequest` refuse a bad request at the HTTP
 * boundary, which is the right place for a browser. But the rules an operator breaks by
 * typing are not the same set as the rules an **importer** would break, and A04 will call
 * these actions directly with no `FormRequest` anywhere in sight. A check that exists only
 * in a FormRequest is a check the importer does not have.
 *
 * So every refusal a caller can provoke is here, expressed as a named domain failure, and
 * the actions in `ManageBillingConfiguration` raise these rather than returning errors.
 * The controllers translate them to 422 and 409; an importer can catch the same objects.
 *
 * The naming is the point. "The client is not a plain lowercase identifier" is a message
 * for a developer; "that client does not exist" and "that value is already configured from
 * that month" are messages an operator can act on.
 */
final class RateRejected extends \RuntimeException
{
    private function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message);
    }

    /**
     * A machine-readable name for the refusal, used as the API `code`.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    // --- identifiers -----------------------------------------------------

    public static function identifierRequired(string $label): self
    {
        return new self(sprintf('Debe indicar el %s al que aplica la configuración.', $label), 'identifier_required');
    }

    public static function clientNotFound(int $clientId): self
    {
        return new self(
            sprintf('El cliente %d no existe.', $clientId),
            'client_not_found',
        );
    }

    public static function companyNotFound(int $companyId): self
    {
        return new self(
            sprintf('La empresa %d no existe.', $companyId),
            'company_not_found',
        );
    }

    // --- the effective month ---------------------------------------------

    public static function effectiveMonthRequired(): self
    {
        return new self(
            'Debe indicar el mes desde el que aplica la configuración.',
            'effective_month_required',
        );
    }

    /**
     * `2026-13` and `2026-99` land here rather than in a Carbon exception or a 500.
     */
    public static function effectiveMonthInvalid(string $given): self
    {
        return new self(
            sprintf(
                'El mes de vigencia «%s» no existe. Debe escribirse como 2026-01-01 o 2026-01, '
                .'con un mes entre 1 y 12.',
                $given,
            ),
            'effective_month_invalid',
        );
    }

    public static function effectiveMonthCannotGoBackwards(mixed $currentMonth): self
    {
        $label = $currentMonth instanceof MonthlyPeriod
            ? $currentMonth->label()
            : 'mes anterior';

        return new self(
            sprintf(
                'No se puede mover la vigencia a un mes anterior a %s. Una decisión se corrige '
                .'hacia adelante; cree una nueva con un mes de vigencia posterior.',
                $label,
            ),
            'effective_month_must_not_go_backwards',
        );
    }

    // --- the amount ------------------------------------------------------

    public static function amountMustBePositive(mixed $amount): self
    {
        return new self(
            sprintf('El valor debe ser mayor que cero; se recibió %s.', (string) $amount),
            'amount_must_be_positive',
        );
    }

    public static function amountMustBeWholePesos(mixed $amount): self
    {
        return new self(
            'El valor debe ser un número entero de pesos, sin decimales. Este sistema no representa centavos.',
            'amount_must_be_whole_pesos',
        );
    }

    // --- the cutoff day and offset ---------------------------------------

    public static function cutoffDayRequired(): self
    {
        return new self('Debe indicar el día de corte.', 'cutoff_day_required');
    }

    public static function cutoffDayOutOfRange(int $day): self
    {
        return new self(
            sprintf('El día de corte debe estar entre 1 y 31; se recibió %d.', $day),
            'cutoff_day_out_of_range',
        );
    }

    public static function offsetRequired(): self
    {
        return new self(
            'Debe indicar si el corte cae en el mismo mes o en el siguiente.',
            'month_offset_required',
        );
    }

    public static function offsetOutOfRange(int $offset): self
    {
        return new self(
            sprintf('El desplazamiento debe ser 0 (mismo mes) o 1 (mes siguiente); se recibió %d.', $offset),
            'month_offset_out_of_range',
        );
    }

    // --- the scope shape -------------------------------------------------

    public static function scopeRequired(): self
    {
        return new self('Debe indicar el alcance de la regla.', 'scope_required');
    }

    public static function scopeInvalid(string $given): self
    {
        return new self(
            sprintf('El alcance «%s» no existe. Use general, company o client.', $given),
            'scope_invalid',
        );
    }

    public static function scopeNeedsCompany(CutoffScope $scope): self
    {
        return new self(
            sprintf('Una regla de alcance «%s» necesita la empresa a la que aplica.', $scope->value),
            'scope_needs_company',
        );
    }

    public static function scopeForbidsCompany(CutoffScope $scope): self
    {
        return new self(
            sprintf('Una regla de alcance «%s» no puede llevar empresa.', $scope->value),
            'scope_forbids_company',
        );
    }

    public static function scopeNeedsClient(CutoffScope $scope): self
    {
        return new self(
            sprintf('Una regla de alcance «%s» necesita el cliente al que aplica.', $scope->value),
            'scope_needs_client',
        );
    }

    public static function scopeForbidsClient(CutoffScope $scope): self
    {
        return new self(
            sprintf('Una regla de alcance «%s» no puede llevar cliente.', $scope->value),
            'scope_forbids_client',
        );
    }

    // --- notes -----------------------------------------------------------

    public static function notesInvalid(): self
    {
        return new self('Las notas deben ser texto.', 'notes_invalid');
    }

    public static function notesTooLong(int $length): self
    {
        return new self(
            sprintf('Las notas son demasiado largas (%d caracteres); el máximo es 2000.', $length),
            'notes_too_long',
        );
    }

    // --- conflicts -------------------------------------------------------

    public static function duplicateRate(int $clientId, int $companyId, mixed $month): self
    {
        $label = $month instanceof MonthlyPeriod ? $month->label() : 'ese mes';

        return new self(
            sprintf(
                'Ya existe un valor configurado para el cliente %d y la empresa %d con vigencia en %s. '
                .'Una decisión por mes: cree la siguiente con una vigencia posterior.',
                $clientId,
                $companyId,
                $label,
            ),
            'rate_already_configured',
        );
    }

    public static function duplicateCutoffRule(
        CutoffScope $scope,
        ?int $companyId,
        ?int $clientId,
        mixed $month,
    ): self {
        $label = $month instanceof MonthlyPeriod ? $month->label() : 'ese mes';
        $subject = match ($scope) {
            CutoffScope::General => 'la regla general',
            CutoffScope::Company => sprintf('la empresa %d', (int) $companyId),
            CutoffScope::Client => sprintf('el cliente %d y la empresa %d', (int) $clientId, (int) $companyId),
        };

        return new self(
            sprintf(
                'Ya existe una fecha de corte para %s con vigencia en %s. Una decisión por mes y '
                .'ámbito: cree la siguiente con una vigencia posterior.',
                $subject,
                $label,
            ),
            'cutoff_rule_already_configured',
        );
    }

    public static function rateNotFound(): self
    {
        return new self('El valor configurado ya no existe.', 'rate_not_found');
    }

    public static function ruleNotFound(): self
    {
        return new self('La fecha de corte ya no existe.', 'cutoff_rule_not_found');
    }
}
