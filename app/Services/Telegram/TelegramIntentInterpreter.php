<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Reads a Spanish sentence into the bot's closed intent set.
 *
 * ## Why this exists at all
 *
 * The previous bot classified with three keyword checks in a fixed order, so a
 * sentence containing "tarea" was always a task query — including "crea una
 * tarea" — and a client's name was never extracted. This interpreter separates
 * the *verb* from the *subject*: it decides whether the person wants to read or
 * write first, then extracts the entity, date, period and status.
 *
 * ## Deterministic, and deliberately so
 *
 * Nothing here calls a provider. A recognised business query is answered by the
 * same services the web application uses even when every AI provider is down,
 * and the model never decides what "deuda de Juan Pérez" means. The only case
 * that may reach the assistant is an unrecognised sentence.
 *
 * Dates are interpreted in the application's effective timezone
 * (`config('app.timezone')`, `America/Bogota` in this deployment). Ambiguous
 * clock times are *not* guessed: "a las 3" without a meridiem is reported as
 * ambiguous so the bot asks, because a reminder at 03:00 and one at 15:00 are
 * different instructions.
 */
final class TelegramIntentInterpreter
{
    /** @var array<string, int> */
    private const MONTHS = [
        'enero' => 1,
        'febrero' => 2,
        'marzo' => 3,
        'abril' => 4,
        'mayo' => 5,
        'junio' => 6,
        'julio' => 7,
        'agosto' => 8,
        'septiembre' => 9,
        'setiembre' => 9,
        'octubre' => 10,
        'noviembre' => 11,
        'diciembre' => 12,
    ];

    /** @var array<string, int> */
    private const WEEKDAYS = [
        'domingo' => 0,
        'lunes' => 1,
        'martes' => 2,
        'miercoles' => 3,
        'jueves' => 4,
        'viernes' => 5,
        'sabado' => 6,
    ];

    /**
     * @param  array<string, mixed>  $context  the endpoint's deterministic memory
     */
    public function interpret(string $text, array $context = []): TelegramInterpretation
    {
        $raw = trim($text);
        $normal = $this->normalise($raw);

        if ($normal === '') {
            return new TelegramInterpretation(TelegramIntent::Unknown);
        }

        $isQuestion = $this->looksLikeQuery($raw, $normal);
        $lower = ' '.$normal.' ';

        // Writes first, unless the sentence is unmistakably a question. This is
        // what keeps "crea una tarea" from being answered as a task list.
        if (! $isQuestion) {
            if ($this->isReminderWrite($lower)) {
                return $this->reminderInterpretation($raw, $normal, $context);
            }

            if ($this->isTaskWrite($lower)) {
                return $this->taskInterpretation($raw, $normal);
            }
        }

        if ($this->mentions($lower, ['documento', 'documentos', 'archivo', 'adjunto'])) {
            return $this->documentInterpretation($raw, $normal, $context);
        }

        if ($this->mentions($lower, ['planilla', 'planillas'])) {
            return new TelegramInterpretation(
                intent: TelegramIntent::Sheets,
                period: $this->extractPeriod($normal),
                sheetStatus: $this->extractSheetStatus($lower),
            );
        }

        if ($this->mentions($lower, ['recordatorio', 'recordatorios'])) {
            return new TelegramInterpretation(
                intent: TelegramIntent::Reminders,
                globalScope: $this->wantsGlobalScope($lower),
            );
        }

        // Money questions come before the generic "pendiente" task wording:
        // "¿cuánto tenemos pendiente por cobrar?" is about cartera, not tasks.
        $clientTerm = $this->extractClientTerm($normal);

        if ($this->mentions($lower, ['deuda', 'saldo', 'cuenta', 'cuanto debe'])) {
            return new TelegramInterpretation(
                intent: TelegramIntent::ClientAccount,
                query: $this->resolveEntityTerm($clientTerm, $context),
            );
        }

        if ($this->mentions($lower, ['cartera', 'por cobrar', 'pendiente por cobrar', 'cuanto tenemos', 'recaudo', 'mora'])) {
            return new TelegramInterpretation(TelegramIntent::Receivables);
        }

        if ($this->mentions($lower, ['tarea', 'tareas', 'debo hacer', 'agenda', 'pendiente', 'pendientes', 'vencida', 'vencidas'])) {
            return new TelegramInterpretation(
                intent: TelegramIntent::Tasks,
                dateScope: $this->extractTaskDateScope($lower),
                globalScope: $this->wantsGlobalScope($lower),
            );
        }

        if ($this->mentions($lower, ['empresa']) && $this->mentions($lower, ['cliente', 'clientes'])) {
            return new TelegramInterpretation(
                intent: TelegramIntent::ClientsByCompany,
                company: $this->extractCompanyTerm($normal),
            );
        }

        if (preg_match('/^busca\b/u', $normal) === 1) {
            return new TelegramInterpretation(
                intent: TelegramIntent::Clients,
                query: $this->resolveEntityTerm($clientTerm ?? '', $context),
            );
        }

        if ($this->mentions($lower, ['cliente', 'clientes'])) {
            return new TelegramInterpretation(
                intent: TelegramIntent::Clients,
                query: $this->resolveEntityTerm($clientTerm ?? '', $context),
            );
        }

        if ($this->mentions($lower, ['ayuda', 'que puedes hacer', 'comandos', 'help'])) {
            return new TelegramInterpretation(TelegramIntent::Help);
        }

        return new TelegramInterpretation(TelegramIntent::Unknown);
    }

    /* ---------------------------------------------------------------------
     | Write interpretation
     | ------------------------------------------------------------------ */

    private function taskInterpretation(string $raw, string $normal): TelegramInterpretation
    {
        $title = $this->extractTitle($raw, 'task');
        $date = $this->extractDate($normal);

        return new TelegramInterpretation(
            intent: TelegramIntent::CreateTask,
            title: $title,
            dueOn: $date,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function reminderInterpretation(string $raw, string $normal, array $context): TelegramInterpretation
    {
        $title = $this->extractTitle($raw, 'reminder');
        $date = $this->extractDate($normal);
        [$time, $ambiguous] = $this->extractTime($normal);

        $reminderAt = null;

        if ($date !== null && $time !== null) {
            $reminderAt = $date->copy()->setTime($time[0], $time[1]);
        }

        return new TelegramInterpretation(
            intent: TelegramIntent::CreateReminder,
            title: $title,
            dueOn: $date,
            reminderAt: $reminderAt,
            ambiguousTime: $ambiguous,
        );
    }

    /* ---------------------------------------------------------------------
     | Document interpretation
     | ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $context
     */
    private function documentInterpretation(string $raw, string $normal, array $context): TelegramInterpretation
    {
        $send = preg_match('/\b(envia|enviame|enviar|manda|mandame|mandar|adjunta|adjuntame|entrega|entregame|comparte|comparteme)\b/u', ' '.$normal.' ') === 1;

        $documentId = null;

        if (preg_match('/\b(?:documento|doc)\s*(?:numero|num|nro|no\.?|id)?\s*(\d{1,12})\b/u', $normal, $matches) === 1) {
            $documentId = (int) $matches[1];
        }

        $term = $this->extractClientTerm($normal);
        $term = $this->resolveEntityTerm($term ?? '', $context);

        $pending = $this->mentions(' '.$normal.' ', ['pendiente', 'pendientes', 'faltan', 'por entregar', 'sin entregar']);
        $approved = $this->mentions(' '.$normal.' ', ['autorizado', 'autorizada', 'aprobado', 'aprobada']);

        // "Envíame el documento de ese cliente" has no name to extract; the
        // previous answer's client is the subject. Resolve it here so the
        // intent does not fall back to a generic document list.
        if ($send && $term === '' && isset($context['last_client_id'])) {
            $term = 'id:'.(int) $context['last_client_id'];
        }

        return new TelegramInterpretation(
            intent: $send && ($documentId !== null || $term !== '' || isset($context['last_document_id']))
                ? TelegramIntent::SendDocument
                : TelegramIntent::Documents,
            query: $term,
            documentId: $documentId,
            sendDocument: $send,
            pendingOnly: $pending,
            approvedOnly: $approved,
        );
    }

    /* ---------------------------------------------------------------------
     | Recognition helpers
     | ------------------------------------------------------------------ */

    private function normalise(string $text): string
    {
        $text = Str::ascii($text);
        $text = mb_strtolower($text);
        $text = preg_replace('/[¿?¡!.,;:]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function looksLikeQuery(string $raw, string $normal): bool
    {
        if (preg_match('/[¿?]/u', $raw) === 1) {
            return true;
        }

        return preg_match(
            '/^(que|cual|cuales|cuanto|cuanta|cuantos|cuantas|quien|quienes|muestra|muestrame|lista|listar|ver|dime|busca|buscar|hay|tengo|mis|necesito|envia|enviame|manda|mandame|adjunta|adjuntame|entrega|entregame)\b/u',
            $normal,
        ) === 1;
    }

    private function isReminderWrite(string $lower): bool
    {
        if (preg_match('/\b(recuerdame|recordarme|recuerda me|programa|programame|programar|agenda|agendame)\b/u', $lower) !== 1) {
            return false;
        }

        // "agenda" as a noun is a task list, not a write.
        return preg_match('/\b(muestrame|lista|listar|ver|que hay|mis)\b/u', $lower) !== 1;
    }

    private function isTaskWrite(string $lower): bool
    {
        return preg_match('/\b(crea|crear|creame|agrega|agregar|anade|anadir|registra|registrar|pon una tarea|pon un recordatorio|nueva tarea|nuevo recordatorio)\b/u', $lower) === 1;
    }

    /**
     * @param  list<string>  $needles
     */
    private function mentions(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function wantsGlobalScope(string $lower): bool
    {
        return preg_match('/\b(todas|todos|equipo|global|globales|completa|completas)\b/u', $lower) === 1;
    }

    /* ---------------------------------------------------------------------
     | Entity extraction
     | ------------------------------------------------------------------ */

    private function extractClientTerm(string $normal): ?string
    {
        $patterns = [
            '/\bdocumentos?\s+(?:pendientes?\s+)?(?:del|de la|de)\s+cliente\s+(.+)$/u',
            '/\bdocumentos?\s+(?:pendientes?\s+)?de\s+(.+)$/u',
            '/\b(?:deuda|saldo|cuenta)(?:\s+pendiente)?\s+(?:de|del|de la)\s+(?:cliente\s+)?(.+)$/u',
            '/\bcuanto\s+debe\s+(.+)$/u',
            '/\bclientes?\s+(?:que\s+)?(?:pertenecen|estan|trabajan)\s+(?:a|en)\s+(?:la\s+)?(?:empresa\s+)?(.+)$/u',
            '/\bclientes?\s+de\s+(?:la\s+)?(?:empresa\s+)?(.+)$/u',
            '/\bbusca(?:r)?\s+(?:a\s+|al\s+|la\s+|el\s+)?(?:cliente\s+)?(.+)$/u',
            '/\bcliente\s+(?:llamad[oa]\s+|identificad[oa]\s+como\s+)?(.+)$/u',
            '/^busca\s+(.+)$/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normal, $matches) !== 1) {
                continue;
            }

            $term = trim($matches[1]);
            $term = preg_replace('/\s+(?:que|con|y|para|del|de la|de)\b.*$/u', '', $term) ?? $term;
            $term = trim($term, " \t\n\r\0\x0B-");

            if ($term !== '') {
                return $term;
            }
        }

        return null;
    }

    private function extractCompanyTerm(string $normal): ?string
    {
        if (preg_match('/\bempresa\s+(.+)$/u', $normal, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Turn "ese cliente" / "identificado" into the previous client, if any.
     *
     * An anaphor with no valid previous subject stays empty; the bot then asks
     * which client is meant instead of guessing.
     *
     * @param  array<string, mixed>  $context
     */
    private function resolveEntityTerm(string $term, array $context): string
    {
        $term = trim($term);

        if ($term === '') {
            return '';
        }

        $anaphors = ['ese', 'esa', 'ese cliente', 'esa clienta', 'el mismo', 'la misma', 'el anterior', 'la anterior', 'identificado', 'identificada', 'mencionado', 'mencionada'];

        if (! in_array($term, $anaphors, true)) {
            return $term;
        }

        $id = $context['last_client_id'] ?? null;

        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            return 'id:'.(int) $id;
        }

        return '';
    }

    /* ---------------------------------------------------------------------
     | Date and time
     | ------------------------------------------------------------------ */

    private function extractTaskDateScope(string $lower): ?string
    {
        if (preg_match('/\b(vencida|vencidas|atrasada|atrasadas|mora)\b/u', $lower) === 1) {
            return 'overdue';
        }

        if (preg_match('/\bmanana\b/u', $lower) === 1) {
            return 'tomorrow';
        }

        if (preg_match('/\bhoy\b/u', $lower) === 1) {
            return 'today';
        }

        return null;
    }

    private function extractDate(string $normal): ?Carbon
    {
        $now = Carbon::now();

        // Explicit ISO date.
        if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/u', $normal, $m) === 1) {
            return $this->safeDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // Day/month with an optional year.
        if (preg_match('/\b(\d{1,2})[\/](\d{1,2})(?:[\/](\d{2,4}))?\b/u', $normal, $m) === 1) {
            $year = isset($m[3]) && $m[3] !== ''
                ? (int) (strlen($m[3]) === 2 ? '20'.$m[3] : $m[3])
                : (int) $now->year;

            return $this->safeDate($year, (int) $m[2], (int) $m[1]);
        }

        // "30 de octubre de 2026".
        if (preg_match('/\b(\d{1,2})\s+de\s+('.implode('|', array_keys(self::MONTHS)).')(?:\s+de\s+(\d{4}))?\b/u', $normal, $m) === 1) {
            $month = self::MONTHS[$m[2]];
            $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $this->nearestYearFor($month, $now);

            return $this->safeDate($year, $month, (int) $m[1]);
        }

        if (preg_match('/\bpasado\s+manana\b/u', $normal) === 1) {
            return $now->copy()->addDays(2)->startOfDay();
        }

        if (preg_match('/\bmanana\b/u', $normal) === 1) {
            return $now->copy()->addDay()->startOfDay();
        }

        if (preg_match('/\bhoy\b/u', $normal) === 1) {
            return $now->copy()->startOfDay();
        }

        foreach (self::WEEKDAYS as $name => $weekday) {
            if (preg_match('/\b'.$name.'\b/u', $normal) !== 1) {
                continue;
            }

            $daysAhead = ($weekday - (int) $now->dayOfWeek + 7) % 7;

            if ($daysAhead === 0) {
                $daysAhead = 7;
            }

            return $now->copy()->addDays($daysAhead)->startOfDay();
        }

        return null;
    }

    private function safeDate(int $year, int $month, int $day): ?Carbon
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return Carbon::create($year, $month, $day, 0, 0, 0);
    }

    private function nearestYearFor(int $month, Carbon $now): int
    {
        return $month > (int) $now->month ? (int) $now->year - 1 : (int) $now->year;
    }

    /**
     * @return array{0: array{0: int, 1: int}|null, 1: bool} time and whether it was ambiguous
     */
    private function extractTime(string $normal): array
    {
        $hour = null;
        $minute = 0;
        $meridiem = '';

        $patterns = [
            '/\ba\s+las?\s+(\d{1,2})(?::(\d{2}))?\s*(de la (tarde|noche|manana)|a\.?\s?m\.?|p\.?\s?m\.?)?/u',
            '/\b(\d{1,2}):(\d{2})\s*(a\.?\s?m\.?|p\.?\s?m\.?)?/u',
            '/\b(\d{1,2})\s*(a\.?\s?m\.?|p\.?\s?m\.?)\b/u',
            '/\b(\d{1,2})\s+de la (tarde|noche|manana)\b/u',
        ];

        foreach ($patterns as $index => $pattern) {
            if (preg_match($pattern, $normal, $m) !== 1) {
                continue;
            }

            $hour = (int) $m[1];
            $minute = $index === 0 || $index === 1 ? (int) ($m[2] ?? 0) : 0;
            $meridiem = $m[3] ?? ($m[2] ?? '');
            break;
        }

        if ($hour === null) {
            return [null, false];
        }

        if ($hour > 23 || $minute > 59) {
            return [null, true];
        }

        $meridiem = mb_strtolower(str_replace(['.', ' '], '', $meridiem));
        $isPm = str_contains($meridiem, 'tarde')
            || str_contains($meridiem, 'noche')
            || $meridiem === 'pm';
        $isAm = str_contains($meridiem, 'manana') || $meridiem === 'am';

        if ($isPm && $hour < 12) {
            $hour += 12;
        }

        if ($isAm && $hour === 12) {
            $hour = 0;
        }

        // A bare "3" is 03:00 or 15:00. Guessing either would silently create a
        // reminder twelve hours away from what the person meant.
        if (! $isPm && ! $isAm && $hour < 7) {
            return [null, true];
        }

        return [[$hour, $minute], false];
    }

    /* ---------------------------------------------------------------------
     | Period and status
     | ------------------------------------------------------------------ */

    private function extractPeriod(string $normal): ?string
    {
        $now = Carbon::now();

        if (preg_match('/\b(\d{4})-(\d{2})\b/u', $normal, $m) === 1) {
            return sprintf('%04d-%02d', (int) $m[1], max(1, min(12, (int) $m[2])));
        }

        if (preg_match('/\bmes\s+pasado\b/u', $normal) === 1) {
            return $now->copy()->subMonthNoOverflow()->format('Y-m');
        }

        if (preg_match('/\beste\s+mes\b/u', $normal) === 1) {
            return $now->format('Y-m');
        }

        if (preg_match('/\b('.implode('|', array_keys(self::MONTHS)).')(?:\s+de\s+(\d{4}))?\b/u', $normal, $m) === 1) {
            $month = self::MONTHS[$m[1]];
            $year = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $this->nearestYearFor($month, $now);

            return sprintf('%04d-%02d', $year, $month);
        }

        return null;
    }

    private function extractSheetStatus(string $lower): ?string
    {
        if (preg_match('/\b(faltan por pagar|sin pagar|no pagadas?|pendientes? de pago|por pagar)\b/u', $lower) === 1) {
            return 'unpaid';
        }

        if (preg_match('/\b(pagadas?|pagados?)\b/u', $lower) === 1) {
            return 'paid';
        }

        if (preg_match('/\bcancelad[ao]s?\b/u', $lower) === 1) {
            return 'cancelled';
        }

        if (preg_match('/\bborrador(es)?\b/u', $lower) === 1) {
            return 'draft';
        }

        if (preg_match('/\blistas?\b/u', $lower) === 1) {
            return 'ready';
        }

        if (preg_match('/\benviadas?\b/u', $lower) === 1) {
            return 'submitted';
        }

        return null;
    }

    /* ---------------------------------------------------------------------
     | Title extraction
     | ------------------------------------------------------------------ */

    private function extractTitle(string $raw, string $kind): ?string
    {
        $title = trim($raw);

        $leading = $kind === 'reminder'
            ? '/^\s*(recu[eé]rdame|recordarme|recuerda(?:\s+me)?|programa(?:me)?|programar|agenda(?:me)?|crea(?:me)?|crear|agrega(?:r)?|a[nñ]ade|a[nñ]adir|registra(?:r)?)\b/iu'
            : '/^\s*(crea(?:me)?|crear|agrega(?:r)?|a[nñ]ade|a[nñ]adir|registra(?:r)?)\b/iu';

        $title = preg_replace($leading, '', $title) ?? $title;
        $title = preg_replace('/^\s*(una|un|la|el)\s+(tarea|recordatorio)\b/iu', '', $title) ?? $title;
        $title = preg_replace('/^\s*(para|de|que)\b/iu', '', $title) ?? $title;

        $removals = [
            '/\bpasado\s+mañana\b/iu',
            '/\bmañana\b/iu',
            '/\bhoy\b/iu',
            '/\b(el\s+)?(lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bado|domingo)\b/iu',
            '/\ba\s+las?\s+\d{1,2}(?::\d{2})?\s*(de la (tarde|noche|mañana)|a\.?\s?m\.?|p\.?\s?m\.?)?/iu',
            '/\b\d{1,2}:\d{2}\s*(a\.?\s?m\.?|p\.?\s?m\.?)?/iu',
            '/\b\d{1,2}\s+de la (tarde|noche|mañana)\b/iu',
            '/\b(el\s+)?\d{1,2}\s+de\s+(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)(\s+de\s+\d{4})?\b/iu',
            '/\b\d{4}-\d{1,2}-\d{1,2}\b/u',
            '/\b\d{1,2}\/\d{1,2}(?:\/\d{2,4})?\b/u',
            '/\bpor favor\b/iu',
        ];

        foreach ($removals as $pattern) {
            $title = preg_replace($pattern, ' ', $title) ?? $title;
        }

        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
        $title = trim($title, " \t\n\r\0\x0B-.,;:");

        if ($title === '' || mb_strlen($title) > 200) {
            $title = $title === '' ? null : mb_substr($title, 0, 200);
        }

        return $title;
    }
}
