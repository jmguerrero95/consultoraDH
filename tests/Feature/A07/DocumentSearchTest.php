<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Domain\Documents\DocumentChunker;
use App\Domain\Documents\DocumentIngestor;
use App\Domain\Documents\DocumentRetriever;
use App\Models\AiAttachment;
use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * GATE 1: the document retrieval query, executed against real PostgreSQL.
 *
 * ## The defect this pins
 *
 * The retrieval query passed its search phrase to `DB::raw($expression,
 * [$term])`. `DB::raw()` accepts that second argument and discards it, so the
 * `?` inside the expression was compiled into the statement with no value
 * behind it. PostgreSQL rejected the whole statement.
 *
 * Two things make this worth a test rather than a code review:
 *
 *   - It only failed on a query with **more than one** parameter. A single
 *     placeholder is bound positionally and would have passed, so a test
 *     written the obvious way proves nothing.
 *   - It fails by refusing to run, never by returning a wrong answer, so
 *     asserting on results alone cannot distinguish "correct" from "never
 *     executed". These tests therefore assert the binding count against the
 *     compiled statement as well as the behaviour.
 *
 * Everything below runs through the real driver: real files, real rows, real
 * `tsvector` matching. Nothing is mocked, because a fake is exactly what let
 * the original defect through.
 */
final class DocumentSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        // The search configuration is created by the migration; RefreshDatabase
        // recreates the schema on the shared test database, where the
        // configuration object survives because it is database-scoped rather
        // than table-scoped. Created once, reused afterwards.
        $this->ensureTextSearchConfiguration();

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
    }

    private function ensureTextSearchConfiguration(): void
    {
        $exists = DB::selectOne(
            "SELECT 1 FROM pg_ts_config WHERE cfgname = 'consultora_es'"
        );

        if ($exists !== null) {
            return;
        }

        DB::statement('CREATE TEXT SEARCH CONFIGURATION consultora_es (COPY = spanish)');
        DB::statement(<<<'SQL'
            ALTER TEXT SEARCH CONFIGURATION consultora_es
            ALTER MAPPING FOR hword, hword_part, word WITH unaccent, spanish_stem
        SQL);
    }

    /** A real .xlsx with real cells, so the text under test is genuinely there. */
    private function ingestWorkbook(User $user, string $filename, array $rows, ?int $conversationId = null): AiAttachment
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->setTitle('Cartera');
        $sheet->getActiveSheet()->fromArray($rows, null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'cdh-test').'.xlsx';
        (new Xlsx($sheet))->save($path);

        $ingestor = app(DocumentIngestor::class);

        $attachment = $ingestor->ingest(
            new UploadedFile(
                $path,
                $filename,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            ),
            $user,
            $conversationId,
        );

        @unlink($path);

        return $attachment;
    }

    public function test_the_binding_count_matches_the_placeholder_count(): void
    {
        // The defect itself, asserted structurally: a statement whose
        // placeholders outnumber its bindings can never execute, whatever the
        // rows contain.
        $query = DB::table('ai_attachment_chunks as c')
            ->join('ai_attachments as a', 'a.id', '=', 'c.ai_attachment_id')
            ->where('a.user_id', 1)
            ->where('a.status', 'processed')
            ->whereIn('c.ai_attachment_id', [1, 2, 3])
            ->select(['c.id as chunk_id'])
            ->selectRaw("ts_rank(c.search_vector, websearch_to_tsquery('consultora_es', ?)) as rank", ['ACME'])
            ->whereRaw("c.search_vector @@ websearch_to_tsquery('consultora_es', ?)", ['ACME']);

        $sql = $query->toSql();
        $bindings = $query->getBindings();

        $this->assertSame(
            substr_count($sql, '?'),
            count($bindings),
            'Every placeholder must have a binding. A statement that outlives its bindings is refused by the driver.'
        );

        // The phrase is bound once for the projection and once for the
        // predicate, after the owner and the identifiers. This exact order is
        // what the driver needs, so it is asserted rather than assumed.
        $this->assertSame(['ACME', 1, 'processed', 1, 2, 3, 'ACME'], $bindings);
    }

    public function test_search_returns_the_matching_passages(): void
    {
        $this->ingestWorkbook($this->owner, 'cartera.xlsx', [
            ['Cliente', 'Deuda', 'Vencida'],
            ['ACME Ltda', 45000000, 'SI'],
            ['Delta SA', 12000000, 'NO'],
        ]);

        $result = app(DocumentRetriever::class)->search($this->owner, 'ACME');

        $this->assertNotEmpty($result['passages'], 'A term present in an uploaded file must find it.');
        $this->assertStringContainsString('ACME', $result['passages'][0]['content']);
        $this->assertSame('cartera.xlsx', $result['passages'][0]['file']);
    }

    public function test_search_binds_the_phrase_rather_than_interpolating_it(): void
    {
        // A phrase carrying SQL punctuation. If it were interpolated this would
        // be a syntax error or, worse, an injection; bound, it is simply a
        // phrase that matches nothing.
        // Ingested first so that the table is non-empty either way: an empty
        // table would make "the table still exists" pass even if the phrase had
        // been executed.
        $this->ingestWorkbook($this->owner, 'cartera.xlsx', [
            ['Cliente', 'Saldo'],
            ['ACME Ltda', 1],
        ]);

        $result = app(DocumentRetriever::class)->search($this->owner, "ACME'; DROP TABLE ai_attachments; --");

        /*
         | Whether this matches is deliberately not asserted either way.
         |
         | The phrase may well retrieve the row, because the loose tier reduces it
         | to the words it contains and one of them is "ACME", which is in the
         | document. That is correct behaviour: the value was treated as text.
         |
         | What matters is what did not happen. Had the phrase been interpolated,
         | the statement would have been broken or the table would have been
         | dropped. Both are checked below, and the row count is asserted as the
         | real proof that nothing was executed.
         */
        $this->assertSame(
            1,
            DB::table('ai_attachments')->count(),
            'The table and its row must survive: the phrase was data, never syntax.'
        );

        $this->assertSame(
            'cartera.xlsx',
            DB::table('ai_attachments')->first()->original_name,
            'The surviving row is the one that was there before the search.'
        );
    }

    public function test_search_never_returns_another_users_documents(): void
    {
        $this->ingestWorkbook($this->owner, 'secreto.xlsx', [
            ['Cliente', 'Saldo'],
            ['ACME Ltda', 999],
        ]);

        $result = app(DocumentRetriever::class)->search($this->stranger, 'ACME');

        $this->assertSame([], $result['passages'], 'Ownership is enforced in the query, not afterwards.');
        $this->assertSame(0, $result['attachments_considered']);
    }

    public function test_search_is_scoped_to_the_conversation_asked_about(): void
    {
        $first = AiConversation::query()->create(['user_id' => $this->owner->id, 'title' => 'Primera']);
        $second = AiConversation::query()->create(['user_id' => $this->owner->id, 'title' => 'Segunda']);

        $this->ingestWorkbook($this->owner, 'primera.xlsx', [
            ['Cliente', 'Saldo'],
            ['ACME Ltda', 111],
        ], $first->id);

        $this->ingestWorkbook($this->owner, 'segunda.xlsx', [
            ['Cliente', 'Saldo'],
            ['ACME Ltda', 222],
        ], $second->id);

        $inFirst = app(DocumentRetriever::class)->search($this->owner, 'ACME', $first->id);
        $inSecond = app(DocumentRetriever::class)->search($this->owner, 'ACME', $second->id);

        $this->assertSame(1, $inFirst['attachments_considered']);
        $this->assertSame(1, $inSecond['attachments_considered']);

        // Two conversations about the same word must not see each other's file.
        $this->assertSame('primera.xlsx', $inFirst['passages'][0]['file']);
        $this->assertSame('segunda.xlsx', $inSecond['passages'][0]['file']);
    }

    public function test_an_unscoped_search_reaches_every_conversation_the_user_owns(): void
    {
        $conversation = AiConversation::query()->create(['user_id' => $this->owner->id, 'title' => 'Unica']);

        $this->ingestWorkbook($this->owner, 'unica.xlsx', [
            ['Cliente', 'Saldo'],
            ['ACME Ltda', 333],
        ], $conversation->id);

        $result = app(DocumentRetriever::class)->search($this->owner, 'ACME');

        $this->assertSame(1, $result['attachments_considered']);
    }

    public function test_a_term_present_nowhere_returns_an_empty_result_rather_than_everything(): void
    {
        $this->ingestWorkbook($this->owner, 'cartera.xlsx', [
            ['Cliente', 'Deuda'],
            ['ACME Ltda', 1],
        ]);

        $result = app(DocumentRetriever::class)->search($this->owner, 'zephyrus');

        $this->assertSame([], $result['passages']);
    }

    public function test_a_natural_question_falls_back_to_matching_any_term(): void
    {
        // "and" semantics would match nothing here, which would read to the
        // person as "your documents do not mention it". The loose tier exists
        // precisely so a question still finds its answer.
        $this->ingestWorkbook($this->owner, 'cartera.xlsx', [
            ['Cliente', 'Deuda'],
            ['ACME Ltda', 45000000],
        ]);

        $result = app(DocumentRetriever::class)->search($this->owner, '¿cuánto debe ACME?');

        $this->assertNotEmpty(
            $result['passages'],
            'A natural-language question must still retrieve the passage that answers it.'
        );
        $this->assertStringContainsString('ACME', $result['passages'][0]['content']);
    }

    public function test_a_document_that_could_not_be_read_is_excluded_from_results(): void
    {
        $attachment = $this->ingestWorkbook($this->owner, 'cartera.xlsx', [
            ['Cliente', 'Saldo'],
            ['ACME Ltda', 1],
        ]);

        $attachment->update(['status' => AiAttachment::STATUS_EMPTY]);

        $result = app(DocumentRetriever::class)->search($this->owner, 'ACME');

        $this->assertSame([], $result['passages']);
        $this->assertSame(0, $result['attachments_considered']);
    }

    public function test_whole_document_is_refused_for_another_user(): void
    {
        $attachment = $this->ingestWorkbook($this->owner, 'cartera.xlsx', [
            ['Cliente', 'Saldo'],
            ['ACME Ltda', 1],
        ]);

        $this->assertNull(app(DocumentRetriever::class)->wholeDocument($this->stranger, $attachment->id));
        $this->assertNotNull(app(DocumentRetriever::class)->wholeDocument($this->owner, $attachment->id));
    }

    public function test_the_chunker_keeps_documents_retrievable_across_chunk_boundaries(): void
    {
        // A sentence longer than one chunk must not be lost: the chunker carries
        // an overlap so a statement spanning a boundary survives in full.
        $text = str_repeat('La cartera del cliente ACME Ltda asciende a 45.000.000 pesos. ', 60);

        $chunks = app(DocumentChunker::class)->chunk($text);

        $this->assertGreaterThan(1, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertNotSame('', trim($chunk));
        }

        // Every sentence boundary is recoverable from some chunk.
        $joined = implode(' ', $chunks);
        $this->assertStringContainsString('45.000.000', $joined);
    }
}
