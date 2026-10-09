<?php

declare(strict_types=1);

namespace App\Domain\Planillas;

use App\Models\ContributionSheet;

/**
 * §22 — the server decides whether a planilla may advance, not the browser.
 *
 * ## What blocks and what does not
 *
 * Errors are only the ones this repository can justify as errors:
 *
 *  - a sheet with no included lines is not a planilla;
 *  - a line without a client or assignment reference cannot be filed;
 *  - the same person twice on one sheet is a duplicate filing;
 *  - an included line without a liquidated amount cannot be advanced, because §10 puts the figure
 *    outside the system — it is a person's operational statement, not something computed here;
 *  - a negative amount is impossible money (§9.3), and the database refuses one anyway;
 *  - `other` without a name, a stale period/company, and structurally inconsistent evidence.
 *
 * Everything else is a warning: a missing EPS/AFP/ARL/CCF, a missing risk class or job title. §22
 * says so directly, and §10 forbids turning a Colombian administrative convention into a legal rule
 * this repository cannot source.
 */
final class ValidateContributionSheet
{
    public function validate(ContributionSheet $sheet): SheetValidation
    {
        $lines = $sheet->lines()->orderBy('id')->get();

        if ($lines->isEmpty()) {
            return new SheetValidation(errors: [[
                'code' => 'no_lines',
                'message' => 'La planilla no tiene líneas. Seleccione al menos una persona.',
                'line_id' => null,
            ]]);
        }

        $errors = [];
        $warnings = [];
        $seen = [];

        if ($sheet->operator->requiresName() && trim((string) $sheet->operator_other_name) === '') {
            $errors[] = [
                'code' => 'operator_not_named',
                'message' => 'Indique el nombre del operador cuando la opción es «Otro».',
                'line_id' => null,
            ];
        }

        foreach ($lines as $line) {
            $ref = $line->client_company_assignment_id;

            // The unique index makes this a database fact too; reporting it here is what tells a
            // reviewer *which* person, instead of letting an insert fail with a constraint name.
            if ($seen[$ref] ?? false) {
                $errors[] = [
                    'code' => 'duplicate_line',
                    'message' => 'Esta persona aparece más de una vez en la planilla.',
                    'line_id' => (int) $line->id,
                ];
            }

            $seen[$ref] = true;

            if ($line->document_number === '' || $line->document_type === '') {
                $errors[] = [
                    'code' => 'missing_client_reference',
                    'message' => 'La línea no tiene una referencia de cliente válida.',
                    'line_id' => (int) $line->id,
                ];
            }

            if ($line->relationship_started_on === null) {
                $errors[] = [
                    'code' => 'missing_assignment_reference',
                    'message' => 'La línea no tiene una referencia de relación válida.',
                    'line_id' => (int) $line->id,
                ];
            }

            if (! $line->included) {
                if (trim((string) $line->exclusion_reason) === '') {
                    $errors[] = [
                        'code' => 'exclusion_without_reason',
                        'message' => 'Explique por qué se excluye esta persona.',
                        'line_id' => (int) $line->id,
                    ];
                }

                continue;
            }

            if ($line->liquidated_amount_cop === null) {
                $errors[] = [
                    'code' => 'missing_liquidated_amount',
                    'message' => 'Indique el valor liquidado de esta persona.',
                    'line_id' => (int) $line->id,
                ];
            } elseif ($line->liquidated_amount_cop < 0) {
                $errors[] = [
                    'code' => 'negative_amount',
                    'message' => 'El valor liquidado no puede ser negativo.',
                    'line_id' => (int) $line->id,
                ];
            }

            if ($line->liquidated_amount_cop === 0) {
                $warnings[] = [
                    'code' => 'zero_amount',
                    'message' => 'El valor liquidado es cero. Confirme que corresponde.',
                    'line_id' => (int) $line->id,
                ];
            }

            // Warnings, not errors: §22 is explicit that an absent provider is a data-quality signal,
            // and §9.6 forbids inventing one.
            foreach ([
                'eps_name' => 'EPS',
                'afp_name' => 'AFP',
                'arl_name' => 'ARL',
                'ccf_name' => 'CCF',
            ] as $column => $label) {
                if (trim((string) $line->{$column}) === '') {
                    $warnings[] = [
                        'code' => 'missing_'.strtolower($column),
                        'message' => 'Esta persona no tiene '.$label.' para el período.',
                        'line_id' => (int) $line->id,
                    ];
                }
            }

            if ($line->arl_name !== null && trim((string) $line->arl_risk_class) === '') {
                $warnings[] = [
                    'code' => 'missing_risk_class',
                    'message' => 'Esta persona tiene ARL pero no tiene clase de riesgo.',
                    'line_id' => (int) $line->id,
                ];
            }

            if (trim((string) $line->job_title) === '') {
                $warnings[] = [
                    'code' => 'missing_job_title',
                    'message' => 'Esta persona no tiene cargo registrado.',
                    'line_id' => (int) $line->id,
                ];
            }
        }

        return new SheetValidation($errors, $warnings);
    }
}
