<?php

declare(strict_types=1);

namespace App\Services\Ai;

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
 *     without a number is ambiguous and is left to the normal assistant.
 *
 * If two tables are attached and the question does not name one, the planner
 * declines rather than combining or picking a file arbitrarily. The normal
 * assistant can then ask which one is meant.
 */
final class TabularReportPlanner
{
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

        $discount = $this->discountPercent($normalised);

        if ($discount === null) {
            return null;
        }

        $attachment = $this->chooseAttachment($conversation, $user, $question);

        if ($attachment === null) {
            return null;
        }

        return new TabularReportPlan(
            attachment: $attachment,
            discountPercent: $discount,
            includeSummary: $this->includeSummary($normalised),
            filename: $this->filename($question),
            sourceName: (string) $attachment->original_name,
        );
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
     * question mentions a discount but does not state one.
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
            '/descuento\s*(?:del|de|:)?\s*(\d{1,3}(?:[.,]\d+)?)\s*(?:%|por ciento)/u',
            '/(\d{1,3}(?:[.,]\d+)?)\s*(?:%|por ciento)\s*(?:de\s*)?descuento/u',
            '/descuento\s*(?:del|de|:)?\s*(\d{1,3}(?:[.,]\d+)?)\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match) !== 1) {
                continue;
            }

            $value = (float) str_replace(',', '.', $match[1]);

            return $value >= 0.0 && $value <= 100.0 ? $value : null;
        }

        // A bare percentage elsewhere is not a discount. The percentage must be
        // attached to the discount word, or the request is ambiguous.
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
