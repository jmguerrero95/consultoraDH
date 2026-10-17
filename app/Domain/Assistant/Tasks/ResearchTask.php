<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tasks;

use App\Domain\Ai\AiMessage;
use App\Domain\Documents\DocumentRetriever;
use App\Models\AiConversation;
use App\Models\AiTask;
use App\Models\User;
use App\Services\Ai\AiPrivacyGate;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\AiToolDeadline;
use App\Services\Ai\AiToolRegistry;
use App\Services\Ai\AiTurnBudget;
use Illuminate\Support\Facades\Log;

/**
 * Answer a research question in the background, from real sources.
 *
 * ## Why this runs as a task
 *
 * Because it is slow and it is interruptible in a way the chat turn is not. A
 * research answer needs a web search, possibly a document search, and a
 * synthesis pass over whatever came back; none of that fits inside a request
 * that the person may close the laptop during, and none of it should be paid
 * for twice when they come back.
 *
 * ## What it is allowed to say
 *
 * Only what a retrieved source said. The model is handed the sources and told
 * to cite them, and the answer it returns is discarded entirely if it names a
 * URL that was not in the input - because a citation the model invented is the
 * single most damaging thing a research tool can do, since it looks exactly
 * like a real one.
 *
 * ## When there are no sources
 *
 * That is a result, not a failure, and it is reported as one. The alternative -
 * letting the model answer from memory - produces a confident paragraph with no
 * provenance, which is the thing this whole feature exists to avoid.
 *
 * ## What it reuses
 *
 * The existing `web_search` and `document_search` tools through the tool
 * registry, and the existing provider resolver for the synthesis pass. No second
 * search tool and no second provider: two of each would drift apart, and the
 * drift would be invisible until the answers disagreed.
 */
final class ResearchTask implements AiTaskHandler
{
    /** Providers answer a synthesis pass in bounded time or not at all. */
    private const SYNTHESIS_TIMEOUT_SECONDS = 60;

    /** Upper bound on sources fed to the model; more is not more useful. */
    private const MAX_SOURCES = 8;

    public function __construct(
        private readonly AiToolRegistry $tools,
        private readonly AiProviderResolver $providers,
        private readonly DocumentRetriever $documents,
        private readonly AiPrivacyGate $privacy,
        private readonly AiToolDeadline $deadline,
    ) {}

    public function kind(): string
    {
        return AiTask::KIND_RESEARCH;
    }

    public function handle(AiTask $task): array
    {
        $user = User::query()->findOrFail($task->user_id);

        $question = trim((string) ($task->input['question'] ?? ''));

        if ($question === '') {
            throw new \RuntimeException('La investigación no tiene una pregunta.');
        }

        // The task's own budget, deliberately separate from a chat turn's: a
        // background research run may reasonably take longer than a request the
        // person is waiting on, and reusing the chat ceiling would fail long work
        // that is behaving correctly. The deadline also caps every outbound tool
        // call made while gathering sources.
        $budget = AiTurnBudget::forTurn(false, microtime(true) + self::SYNTHESIS_TIMEOUT_SECONDS);
        $this->deadline->set($budget->deadline);

        try {
            $task->forceFill(['stage' => 'Buscando fuentes', 'progress' => 20])->save();

            $sources = $this->gather($user, $question, $task->ai_conversation_id);

            $task->forceFill([
                'stage' => $sources === [] ? 'Sin fuentes' : 'Sintetizando',
                'progress' => 60,
            ])->save();

            // No sources is reported, not papered over. An answer nobody can check is
            // worse than an honest "no encontré nada".
            if ($sources === []) {
                return [
                    'question' => $question,
                    'summary' => null,
                    'sources' => [],
                    'outcome' => 'no_sources',
                ];
            }

            $summary = $this->synthesize($user, $question, $sources, $task, $budget);

            return [
                'question' => $question,
                'summary' => $summary,
                // The sources travel with the answer, always, and are never edited:
                // the citations are what make the summary worth anything.
                'sources' => $sources,
                'outcome' => $summary === null ? 'sources_only' : 'answered',
            ];
        } finally {
            $this->deadline->set(null);
        }
    }

    /**
     * Retrieve sources from the tools that already exist.
     *
     * Each tool failing is tolerable on its own - search being unavailable does
     * not mean the document search is - so failures are collected rather than
     * thrown, and the caller decides what to do with whatever survived.
     *
     * @return list<array{title: string, url: string, domain: string, snippet: string}>
     */
    private function gather(User $user, string $question, ?int $conversationId): array
    {
        $sources = [];
        $seen = [];

        // The person's own attachments come first: they are the most relevant
        // thing they could have, they are already authorised, and they are what
        // "investigate this spreadsheet I just gave you" is asking about.
        //
        // Scoped to this task's conversation, exactly as an assistant turn is, so
        // a document attached to a different thread stays out - and so a
        // conversation's contents never leak into another's research.
        foreach ($this->attachments($user, $question, $conversationId) as $source) {
            $sources[] = $source;
        }

        foreach ([
            ['tool' => 'document_search', 'arguments' => ['query' => $question, 'max_results' => 5]],
            ['tool' => 'web_search', 'arguments' => ['query' => $question, 'max_results' => 5]],
        ] as $attempt) {
            try {
                $output = $this->tools->executeRead($attempt['tool'], $attempt['arguments'], $user);
            } catch (\Throwable $e) {
                // Recorded rather than rethrown. A provider that is down, a
                // permission this person does not hold, a disabled feature: each
                // of those narrows the answer and none of them should stop the
                // other source from being tried.
                Log::info('Research source unavailable.', [
                    'tool' => $attempt['tool'],
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($this->normalise($output) as $source) {
                $key = $source['url'] !== '' ? $source['url'] : $source['title'];

                // Deduplicated across both tools: the same page reached through
                // two searches is one source, and listing it twice makes the
                // evidence look stronger than it is.
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $sources[] = $source;

                if (count($sources) >= self::MAX_SOURCES) {
                    return $sources;
                }
            }
        }

        return $sources;
    }

    /**
     * Passages from the documents attached to the task's conversation.
     *
     * @return list<array{title: string, url: string, domain: string, snippet: string}>
     */
    private function attachments(User $user, string $question, ?int $conversationId): array
    {
        if ($conversationId === null) {
            return [];
        }

        try {
            $found = $this->documents->search($user, $question, $conversationId);
        } catch (\Throwable $e) {
            Log::info('Research attachments unavailable.', ['reason' => $e->getMessage()]);

            return [];
        }

        $sources = [];

        foreach ($found['passages'] ?? [] as $passage) {
            $file = (string) ($passage['file'] ?? 'documento');

            $sources[] = [
                // Named like a source so the synthesis prompt treats it the same
                // way as a web page: something to cite, not something to obey.
                'title' => $file,
                // Not a URL. Inventing one for a local file would produce a
                // citation that resolves to nothing.
                'url' => '',
                'domain' => 'adjunto',
                'snippet' => trim((string) ($passage['content'] ?? '')).'
'
                    .'(fragmento '.($passage['chunk'] ?? '?').')',
            ];

            if (count($sources) >= self::MAX_SOURCES) {
                break;
            }
        }

        return $sources;
    }

    /**
     * @return list<array{title: string, url: string, domain: string, snippet: string}>
     */
    private function normalise(mixed $output): array
    {
        $rows = [];

        if (is_array($output)) {
            $rows = $output['results'] ?? $output['passages'] ?? $output;
        }

        if (! is_array($rows)) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $url = (string) ($row['url'] ?? '');

            $out[] = [
                'title' => trim((string) ($row['title'] ?? '')) ?: $url,
                'url' => $url,
                'domain' => (string) ($row['domain'] ?? ($url !== '' ? parse_url($url, PHP_URL_HOST) : '')),
                'snippet' => trim((string) ($row['snippet'] ?? $row['content'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * Ask the configured free model to summarise the sources - and throw the
     * answer away if it cites anything they do not contain.
     *
     * @param  list<array{title: string, url: string, domain: string, snippet: string}>  $sources
     */
    private function synthesize(User $user, string $question, array $sources, AiTask $task, AiTurnBudget $budget): ?string
    {
        // Numbered so the model cites "[1]" rather than restating a URL and
        // getting it subtly wrong.
        $catalogue = [];

        foreach ($sources as $index => $source) {
            $catalogue[] = [
                'n' => $index + 1,
                'title' => $source['title'],
                'url' => $source['url'],
                'snippet' => $source['snippet'],
            ];
        }

        // A research synthesis may carry private data even when the question
        // looks public: the sources can include fragments of an attached
        // spreadsheet, and the question can name a client. The classification is
        // decided from the provenance the task travelled with *and* what the
        // payload actually contains, and it is persisted, so the thread stays
        // protected afterwards.
        $texts = [$question];

        foreach ($sources as $source) {
            $texts[] = $source['title'].' '.$source['snippet'];
        }

        $requiresClientData = $this->privacy->payloadRequiresClientData(
            conversationFlagged: (bool) ($task->input['contains_client_data'] ?? false) || $this->conversationIsPrivate($task),
            hasPrivateDocuments: $this->hasAttachmentSources($sources),
            texts: $texts,
        );

        if ($requiresClientData) {
            $this->markConversationPrivate($task);
        }

        $system = <<<'PROMPT'
        Escribes un resumen breve y fiel a partir de las FUENTES que se te entregan.

        Reglas, sin excepción:
        - Usa únicamente lo que dicen las fuentes. No añadas datos, cifras, fechas ni nombres que no estén ahí.
        - Cita con el número de la fuente entre corchetes, por ejemplo [1] o [2][3].
        - No inventes ninguna URL. Si una fuente no aparece en la lista, no puedes citarla.
        - Si las fuentes no alcanzan para responder, escribe exactamente: NO_ALCANZA
        - Escribe en español claro y directo, sin preámbulos.

        Responde solo con el texto del resumen.
        PROMPT;

        try {
            $response = $this->providers->complete(
                [
                    AiMessage::system($system),
                    AiMessage::user(
                        "PREGUNTA:\n".$question."\n\nFUENTES:\n".json_encode($catalogue, JSON_UNESCAPED_UNICODE),
                    ),
                ],
                [],
                null,
                'research',
                $budget->deadline,
                $requiresClientData,
                $budget,
            );
        } catch (AiProviderException $e) {
            // Quota, upstream 429, or no slot configured at all. The sources are
            // already the deliverable, so they are returned with no summary
            // rather than the task being failed.
            Log::info('Research synthesis unavailable; returning sources only.', [
                'reason' => $e->getMessage(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('Research synthesis failed.', ['reason' => $e->getMessage()]);

            return null;
        }

        $text = trim($response->content);

        if ($text === '' || $text === 'NO_ALCANZA') {
            return null;
        }

        return $this->citationsAreReal($text, $catalogue) ? $text : null;
    }

    /**
     * Whether the task's conversation already holds private data.
     */
    private function conversationIsPrivate(AiTask $task): bool
    {
        if ($task->ai_conversation_id === null) {
            return false;
        }

        return (bool) AiConversation::query()
            ->whereKey($task->ai_conversation_id)
            ->value('contains_client_data');
    }

    /**
     * Persist the private mark on the task's conversation, once.
     */
    private function markConversationPrivate(AiTask $task): void
    {
        if ($task->ai_conversation_id === null) {
            return;
        }

        AiConversation::query()
            ->whereKey($task->ai_conversation_id)
            ->where('contains_client_data', false)
            ->update(['contains_client_data' => true]);
    }

    /**
     * Whether any source is a fragment of an attached document.
     *
     * @param  list<array{domain: string}>  $sources
     */
    private function hasAttachmentSources(array $sources): bool
    {
        foreach ($sources as $source) {
            if (($source['domain'] ?? '') === 'adjunto') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every citation in the answer points at a source that exists.
     *
     * This is the check that makes a research answer trustworthy. A model that
     * cites [9] when there are three sources, or a URL that was never retrieved,
     * has produced something that looks citable and is not, and it is discarded
     * whole rather than repaired.
     *
     * @param  list<array{n: int, url: string}>  $catalogue
     */
    private function citationsAreReal(string $text, array $catalogue): bool
    {
        preg_match_all('/\[(\d+)\]/', $text, $matches);

        if ($matches[0] === []) {
            // No citations at all. Allowed: a summary that simply restates the
            // sources is not a fabrication, and requiring citations would reject
            // a perfectly good answer over formatting.
            return true;
        }

        $available = array_map(static fn (array $s): int => $s['n'], $catalogue);

        foreach ($matches[1] as $number) {
            if (! in_array((int) $number, $available, true)) {
                return false;
            }
        }

        // And no URL that was not retrieved.
        preg_match_all('~https?://[^\s\]\)]+~', $text, $urls);

        if ($urls[0] === []) {
            return true;
        }

        $permitted = array_map(static fn (array $s): string => rtrim($s['url'], '/'), $catalogue);
        $permitted = array_filter($permitted);

        foreach ($urls[0] as $url) {
            $needle = rtrim($url, '/.,;');

            if (! in_array($needle, $permitted, true)) {
                return false;
            }
        }

        return true;
    }
}
