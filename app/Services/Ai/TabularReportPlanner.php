<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Ai\AiToolRejected;
use App\Models\AiAttachment;
use App\Models\AiConversation;
use App\Models\User;

/**
 * Recognises an unambiguous request for a receivables workbook.
 *
 * This is deliberately a small, deterministic reader of the *question*, not a
 * second language model. The model may still interpret requests that need it;
 * this planner only takes over when all of the following are explicit:
 *
 *   - the person points at an attachment in this conversation;
 *   - the request is about a receivables/receivables-like table;
 *   - a workbook/export is requested;
 *   - the discount is either absent (zero) or stated. A mention of a discount
 *     without a number is ambiguous and is left to the normal assistant;
 *   - the request asks for the standard report, not for a transformation this
 *     planner cannot honestly perform (grouping, filtering, sorting, date
 *     ranges, pivots, charts, selected columns). Those are left to the normal
 *     assistant rather than silently answered with an unchanged report;
 *   - when the workbook has several sheets, the question names exactly one of
 *     them. Otherwise the planner declines and the person is asked.
 *
 * If two tables are attached and the question does not name one, the planner
 * declines rather than combining or picking a file arbitrarily. The normal
 * assistant can then ask which one is meant.
 */
final class TabularReportPlanner
{
    /**
     * Instructions the deterministic workbook does not implement.
     *
     * The report has one fixed shape: every source row, with a balance,
     * discount and adjusted balance, plus a summary. A request that asks for a
     * different population, order, grouping or shape cannot be satisfied by
     * that workbook, and producing it anyway would answer a different question
     * than the one asked. Recognising the mismatch and stepping aside is the
     * honest outcome; the normal assistant can then explain or ask.
     */
    private const UNSUPPORTED_INTENTS = [
        '/\bagrup\w*/u',                       // agrupar, agrupado, agrupación
        '/\bfiltr\w*/u',                       // filtrar, filtro, filtrado
        '/\b(?:incluye|incluir)\s+solo\b/u',
        '/\bsolo\s+(?:los|las)\b/u',
        '/\b(?:quita|quitar|elimina|eliminar)\b/u',
        '/\b(?:vencid|moros)\w*/u',
        '/\b(?:orden|ordena|ordenar|ordenado)\w*/u',
        '/\btop\s*\d+/u',
        '/\b(?:primeros?|ultimos?|últimos?)\s+\d+/u',
        '/\brango\w*/u',
        '/\bentre\s+\d+\s+y\s+\d+/u',
        '/\b(?:desde|hasta)\s+(?:el\s+)?\d/u',
        '/\b(?:fecha|periodo|mes)\b/u',
        '/\bcolumnas?\b/u',
        '/\bpivot\w*/u',
        '/\btabla\s+dinamica\b/u',
        '/\b(?:grafic|chart)\w*/u',
        '/\b(?:subtotal|total|promedio)\w*\s+por\b/u',
        '/\bcuantos?\b/u',
        '/\bque\s+(?:tengan|sean|esten|deban|deben|pagaron|no\s+deban)\b/u',
        '/\bmayor(?:es)?\s+(?:a|que)\b/u',
        '/\bmenor(?:es)?\s+(?:a|que)\b/u',
        '/\b(?:superior|inferior)\s+(?:a|que)\b/u',
        '/\bexcluir\b/u',
    ];

    public function __construct(
        private readonly TabularAttachmentReader $reader,
    ) {}

    /**
     * @return TabularReportPlan|null null means "not certainly this operation"
     */
    public function plan(AiConversation $conversation, User $user, string $question): ?TabularReportPlan
    {
        if ($user->cannot('ai.use') || $user->cannot('artifacts.create')) {
            return null;
        }

        $normalised = $this->normalise($question);

        if (! $this->isReportRequest($normalised)) {
            return null;
        }

        if ($this->asksForUnsupportedShape($normalised)) {
            return null;
        }

        $discount = $this->discountPercent($normalised);

        if ($discount === null) {
            return null;
        }

        $attachment = $this->chooseAttachment($conversation, $user, $question);

        if ($attachment === null) {
            return null;
        }

        $sheet = $this->sheetFor($attachment, $normalised);

        if ($sheet === null) {
            return null;
        }

        return new TabularReportPlan(
            attachment: $attachment,
            discountPercent: $discount,
            includeSummary: $this->includeSummary($normalised),
            filename: $this->filename($question),
            sourceName: (string) $attachment->original_name,
            sheetName: $sheet['sheet'],
        );
    }

    private function asksForUnsupportedShape(string $text): bool
    {
        foreach (self::UNSUPPORTED_INTENTS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide which sheet the report should read.
     *
     * A delimited file and a single-sheet workbook need no name. A workbook
     * with several sheets needs exactly one named in the question; anything
     * else is left to the normal assistant, which can ask. `null` therefore
     * means "cannot decide honestly", not "there is no sheet".
     *
     * @return array{sheet: string|null}|null
     */
    private function sheetFor(AiAttachment $attachment, string $normalisedQuestion): ?array
    {
        try {
            $names = $this->reader->sheetNames($attachment);
        } catch (AiToolRejected) {
            // An unreadable or oversized workbook is not a certain report
            // request. Let the normal assistant surface the real problem.
            return null;
        }

        if ($names === []) {
            return ['sheet' => null];
        }

        if (count($names) === 1) {
            return ['sheet' => $names[0]];
        }

        $matches = [];

        foreach ($names as $name) {
            $normalisedName = $this->normalise($name);

            if ($normalisedName === '') {
                continue;
            }

            $pattern = '/(?<![a-z0-9])'.preg_quote($normalisedName, '/').'(?![a-z0-9])/u';

            if (preg_match($pattern, $normalisedQuestion) === 1) {
                $matches[] = $name;
            }
        }

        return count($matches) === 1 ? ['sheet' => $matches[0]] : null;
    }

    private function isReportRequest(string $text): bool
    {
        $pointsAtAttachment = preg_match(
            '/\b(adjunt\w*|subi\w*|cargu\w*|archivo que (?:subi|cargue|adjunte))\b/u',
            $text,
        ) === 1;

        $spreadsheet = preg_match(
            '/\b(excel|xlsx|libro|hoja de calculo|planilla|reporte|archivo)\b/u',
            $text,
        ) === 1;

        $receivables = preg_match(
            '/\b(cartera|obligacion\w*|pagad\w*|pago\w*|saldo\w*|deuda\w*|deudor\w*|recuperacion|cobrar)\b/u',
            $text,
        ) === 1;

        $generation = preg_match(
            '/\b(genera\w*|crea\w*|crear|exporta\w*|convierte\w*|convertir|elabora\w*|prepara\w*|haz|hacer)\b/u',
            $text,
        ) === 1;

        return $pointsAtAttachment && $spreadsheet && $receivables && $generation;
    }

    /**
     * The requested percentage, zero when none was requested, or null when the
     * question mentions a discount but does not state one unambiguously.
     *
     * The rate must be written as a percentage — with `%` or `por ciento`.
     * A bare number after "descuento" is not a rate: "descuento de 10.000 COP"
     * is an amount, and treating it as 10 % would apply a discount nobody
     * asked for. A dot followed by exactly three digits is read as a thousands
     * separator and therefore as ambiguous, not as a decimal rate.
     */
    private function discountPercent(string $text): ?float
    {
        if (preg_match('/\b(sin|no aplicar|no aplicar ningun|ningun) descuento\b/u', $text) === 1) {
            return 0.0;
        }

        if (! str_contains($text, 'descuento')) {
            return 0.0;
        }

        $patterns = [
            '/descuento\s*(?:del|de|:)?\s*(\d{1,3}(?:[.,]\d+)?)\s*(?:%|por\s+ciento)/u',
            '/(\d{1,3}(?:[.,]\d+)?)\s*(?:%|por\s+ciento)\s*(?:de\s*)?descuento/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match) !== 1) {
                continue;
            }

            $raw = $match[1];

            if (preg_match('/^\d{1,3}\.\d{3}$/', $raw) === 1) {
                return null;
            }

            $value = (float) str_replace(',', '.', $raw);

            return $value >= 0.0 && $value <= 100.0 ? $value : null;
        }

        // A discount mentioned without a percentage is ambiguous. A bare
        // percentage elsewhere in the question is not a discount either.
        return null;
    }

    private function includeSummary(string $text): bool
    {
        if (preg_match('/\b(sin|no incluir|no incluyas|omitir|omite)\s+(?:la\s+)?(?:hoja\s+)?resumen\b/u', $text) === 1) {
            return false;
        }

        if (preg_match('/\bsolo\s+(?:la\s+)?(?:hoja\s+)?cartera\b/u', $text) === 1) {
            return false;
        }

        return true;
    }

    private function chooseAttachment(AiConversation $conversation, User $user, string $question): ?AiAttachment
    {
        $attachments = AiAttachment::query()
            ->where('user_id', $user->id)
            ->where('ai_conversation_id', $conversation->id)
            ->where('status', AiAttachment::STATUS_PROCESSED)
            ->whereIn('extension', TabularAttachmentReader::TABULAR_EXTENSIONS)
            ->orderByDesc('id')
            ->get();

        if ($attachments->isEmpty()) {
            return null;
        }

        if ($attachments->count() === 1) {
            return $attachments->first();
        }

        // Several possible sources: only proceed when the question names one
        // unambiguously. Otherwise the normal assistant asks; never combine files.
        $named = $attachments->filter(function (AiAttachment $attachment) use ($question): bool {
            $name = mb_strtolower((string) $attachment->original_name);

            if ($name !== '' && str_contains(mb_strtolower($question), $name)) {
                return true;
            }

            $stem = mb_strtolower((string) pathinfo((string) $attachment->original_name, PATHINFO_FILENAME));

            return $stem !== '' && str_contains(mb_strtolower($question), $stem);
        })->values();

        return $named->count() === 1 ? $named->first() : null;
    }

    private function filename(string $question): string
    {
        $patterns = [
            '/(?:llamad[oa]|denominad[oa]|nombre(?:\s+de\s+archivo)?)\s+["“\']([^"”\']+\.xlsx)["”\']/iu',
            '/(?:llamad[oa]|denominad[oa]|nombre(?:\s+de\s+archivo)?)\s+([^\s,;]+\.xlsx)/iu',
            '/archivo\s+["“\']([^"”\']+\.xlsx)["”\']/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $question, $match) !== 1) {
                continue;
            }

            $name = trim($match[1]);

            if ($name !== '') {
                return mb_substr($name, 0, 150);
            }
        }

        return 'Cartera_Resumen';
    }

    private function normalise(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
