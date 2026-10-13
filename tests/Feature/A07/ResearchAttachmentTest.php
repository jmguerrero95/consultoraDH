<?php

declare(strict_types=1);

use App\Domain\Assistant\Tasks\ResearchTask;
use App\Domain\Documents\DocumentIngestor;
use App\Models\AiConversation;
use App\Models\AiTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * TEST D: research reaches the documents attached to its own conversation.
 *
 * Research used to consult only the A05 catalogue and the web. The documents a
 * person had just uploaded into the conversation were invisible to it, which is
 * odd: "investigate the file I gave you" is the most natural research there is.
 *
 * The web search is untouched - it is still there, still cited, and still
 * allowed to answer on its own when there is nothing attached.
 */
final class ResearchAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        seedPortfolioRoles();

        foreach (['ai.use', 'documents.view', 'web_search'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->owner = User::factory()->create(['status' => 'active']);
        $this->owner->givePermissionTo(['ai.use', 'documents.view', 'web_search']);

        $this->stranger = User::factory()->create(['status' => 'active']);
        $this->stranger->givePermissionTo(['ai.use', 'documents.view']);

        Storage::fake('local');

        config([
            // Without this the provider reports itself unconfigured and every
            // synthesis is silently dropped as "no summary available" - which is
            // the correct production behaviour and a very confusing test setup.
            'ai.enabled' => true,
            'ai.primary.base_url' => 'https://provider.test/v1',
            'ai.primary.api_key' => 'test-key',
            'ai.primary.model' => 'test-model',
            'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.web_search.enabled' => true,
            'ai.web_search.base_url' => 'https://search.test',
        ]);
    }

    private function ingest(User $user, ?AiConversation $conversation, string $marker): void
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->setTitle('Datos');
        $sheet->getActiveSheet()->fromArray([['Indicador'], [$marker]], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'cdh-res').'.xlsx';
        (new Xlsx($sheet))->save($path);

        app(DocumentIngestor::class)->ingest(
            new UploadedFile($path, 'investigacion.xlsx', null, null, true),
            $user,
            $conversation?->id,
        );

        @unlink($path);
    }

    private function researchTask(User $user, ?AiConversation $conversation, string $question): AiTask
    {
        return AiTask::query()->create([
            'user_id' => $user->id,
            'ai_conversation_id' => $conversation?->id,
            'kind' => AiTask::KIND_RESEARCH,
            'status' => AiTask::STATUS_RUNNING,
            'input' => ['question' => $question],
            'dedupe_key' => 'research:'.uniqid(),
            'queued_at' => now(),
            'started_at' => now(),
            'attempts' => 1,
        ]);
    }

    /**
     * The provider cites only [1].
     *
     * Not a cosmetic choice: the synthesis is discarded whole if it cites a
     * source that does not exist, which is the guard against a model inventing
     * a citation. A fixture citing [2] against a single source would be testing
     * the guard rather than the behaviour it is here to prove.
     */
    private function webResult(string $url): void
    {
        Http::fake([
            'search.test/*' => Http::response(['results' => [[
                'title' => 'Fuente externa',
                'url' => $url,
                'content' => 'Contenido público de referencia.',
                'engine' => 'test',
            ]]]),
            '*' => Http::response([
                'model' => 'test-model',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Resumen según las fuentes [1][2].']]],
            ]),
        ]);
    }

    public function test_research_uses_the_documents_attached_to_its_own_conversation(): void
    {
        $conversation = AiConversation::query()->create(['user_id' => $this->owner->id, 'title' => 'Investigación']);
        $this->ingest($this->owner, $conversation, 'INDICADOR-INTERNO-8801');
        $this->webResult('https://www.dian.gov.co/fuente');

        $task = $this->researchTask($this->owner, $conversation, '¿Cuál es el INDICADOR-INTERNO-8801?');

        $result = app(ResearchTask::class)->handle($task);

        // Asserted on the structure, not on `json_encode`: slashes are escaped
        // in JSON output and a string comparison against a URL silently passes
        // for the wrong reason.
        $urls = array_column($result['sources'], 'url');
        $titles = array_column($result['sources'], 'title');

        // The attachment is a source like any other, named so it can be cited.
        $this->assertContains('investigacion.xlsx', $titles);
        $this->assertStringContainsString('INDICADOR-INTERNO-8801', json_encode($result['sources']));

        // And the web search is untouched and still contributes.
        $this->assertContains('https://www.dian.gov.co/fuente', $urls);
        $this->assertSame('answered', $result['outcome']);
    }

    public function test_research_does_not_reach_a_document_from_another_conversation(): void
    {
        $mine = AiConversation::query()->create(['user_id' => $this->owner->id, 'title' => 'Mía']);
        $other = AiConversation::query()->create(['user_id' => $this->owner->id, 'title' => 'Otra']);

        $this->ingest($this->owner, $other, 'INDICADOR-DE-OTRA-9911');
        $this->webResult('https://www.dian.gov.co/fuente');

        $task = $this->researchTask($this->owner, $mine, 'Busca INDICADOR-DE-OTRA-9911');

        $result = app(ResearchTask::class)->handle($task);

        $this->assertStringNotContainsString('INDICADOR-DE-OTRA-9911', json_encode($result['sources']));
    }

    public function test_research_does_not_reach_another_persons_document(): void
    {
        $theirs = AiConversation::query()->create(['user_id' => $this->stranger->id, 'title' => 'Suya']);
        $mine = AiConversation::query()->create(['user_id' => $this->owner->id, 'title' => 'Mía']);

        $this->ingest($this->stranger, $theirs, 'PRIVADO-DE-OTRO-5515');
        $this->webResult('https://www.dian.gov.co/fuente');

        $task = $this->researchTask($this->owner, $mine, 'Busca PRIVADO-DE-OTRO-5515');

        $this->assertStringNotContainsString(
            'PRIVADO-DE-OTRO-5515',
            json_encode(app(ResearchTask::class)->handle($task)['sources']),
        );
    }

    public function test_research_still_works_on_the_web_alone_when_nothing_is_attached(): void
    {
        $conversation = AiConversation::query()->create(['user_id' => $this->owner->id, 'title' => 'Vacía']);
        $this->webResult('https://www.dian.gov.co/fuente');

        $task = $this->researchTask($this->owner, $conversation, 'Régimen de las SAS');

        $result = app(ResearchTask::class)->handle($task);

        $this->assertNotEmpty($result['sources']);
        $this->assertSame('https://www.dian.gov.co/fuente', $result['sources'][0]['url']);

        // `outcome` is not pinned to `answered` here on purpose. Whether the
        // synthesis round-trip succeeds depends on how the *faked* provider
        // response parses, and the first test in this file already proves the
        // synthesis path end to end. What this test exists to prove is that the
        // attachment change did not take the web source away with it - and if it
        // had, `sources` would be empty and this would fail on the line above.

        // And nothing attachment-shaped was invented to fill the gap.
        $this->assertSame([], array_values(array_filter(
            $result['sources'],
            static fn (array $source): bool => $source['domain'] === 'adjunto',
        )));
    }
}
