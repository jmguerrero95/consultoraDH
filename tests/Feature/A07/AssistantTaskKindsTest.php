<?php

declare(strict_types=1);

use App\Domain\Assistant\AiTaskService;
use App\Domain\Assistant\Tasks\GenerateDocumentTask;
use App\Models\AiArtifact;
use App\Models\AiConversation;
use App\Models\AiTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GATE 7, interventions 1 and 3.
 *
 * ## What is actually exercised
 *
 * A real Redis queue and the real `Worker` - not `Queue::fake()` - for every
 * job. The point of these tests is the join: that a request made over HTTP
 * becomes a row, that a worker picks that row up, and that what comes out is
 * retrievable afterwards.
 *
 * ## The providers are faked
 *
 * Research and document drafting call a model. Those calls are faked so the
 * suite is deterministic and spends no free-tier quota; everything around them
 * - the queue, the worker, the handlers, the artifacts, the retrieval - is the
 * real thing.
 */
final class AssistantTaskKindsTest extends TestCase
{
    use RefreshDatabase;

    private const QUEUE = 'ai-tasks';

    private User $user;

    private User $stranger;

    private AiConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        seedPortfolioRoles();

        foreach (['ai.use', 'documents.view', 'artifacts.create', 'web_search'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['status' => 'active']);
        $this->user->givePermissionTo(['ai.use', 'documents.view', 'artifacts.create', 'web_search']);

        $this->stranger = User::factory()->create(['status' => 'active']);
        $this->stranger->givePermissionTo(['ai.use', 'documents.view', 'artifacts.create']);

        $this->conversation = AiConversation::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Conversación',
        ]);

        Storage::fake('local');

        config([
            'queue.default' => 'redis',
            'ai.tasks.tries' => 3,
            'ai.tasks.backoff' => [0, 0],
            'ai.suggestions.reasoning.enabled' => false,
            'ai.web_search.enabled' => true,
        ]);

        // Cleared once here, never by the reader below. A helper that empties
        // the queue when asked how deep it is measures nothing but its own
        // side effect - and silently skipped the worker entirely.
        $this->redisQueue()->clear(self::QUEUE);
    }

    protected function tearDown(): void
    {
        // The suite owns this queue and only this queue. Clearing it on the way
        // out keeps a job that a test deliberately left waiting (to assert its
        // depth) from surviving into the next case or the next run. `clear()`
        // removes the ready, delayed, reserved and notify keys in one call.
        $this->redisQueue()->clear(self::QUEUE);

        parent::tearDown();
    }

    private function redisQueue(): RedisQueue
    {
        $queue = app('queue')->connection('redis');
        $this->assertInstanceOf(RedisQueue::class, $queue);

        return $queue;
    }

    private function work(): int
    {
        $worker = app('queue.worker');
        $options = new WorkerOptions(maxTries: (int) config('ai.tasks.tries', 3), sleep: 0);
        $processed = 0;

        while ($this->redisQueue()->size(self::QUEUE) > 0 && $processed < 20) {
            $worker->runNextJob('redis', self::QUEUE, $options);
            $processed++;
        }

        return $processed;
    }

    private function workbook(): UploadedFile
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->setTitle('Cartera');
        $sheet->getActiveSheet()->fromArray([
            ['Cliente', 'Saldo'],
            ['MARIA LOPEZ', 45000000],
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'cdh-kinds').'.xlsx';
        (new Xlsx($sheet))->save($path);

        return new UploadedFile($path, 'cartera.xlsx', null, null, true);
    }

    // =====================================================================
    // T1 - research
    // =====================================================================

    public function test_research_is_queued_and_processed_with_real_sources(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/ai/tasks/research', [
            'question' => '¿Cuál es el régimen tributario de las empresas SAS?',
            'conversation_id' => $this->conversation->id,
        ]);

        $response->assertAccepted()->assertJsonPath('task.status', AiTask::STATUS_QUEUED);

        $taskId = (int) $response->json('task.id');
        $this->assertSame(1, $this->redisQueue()->size(self::QUEUE), 'Queued on Redis.');

        // The web search is the only source available here (no documents indexed),
        // and it is faked at the HTTP boundary so the run is deterministic.
        $this->fakeSearch([
            ['title' => 'Régimen SAS', 'url' => 'https://www.dian.gov.co/regimen-sas', 'snippet' => 'Las SAS son sociedades por acciones simplificada.'],
        ]);

        $this->work();

        $task = AiTask::query()->findOrFail($taskId);

        $this->assertSame(AiTask::STATUS_SUCCEEDED, $task->status, (string) $task->error);
        $this->assertSame(100, $task->progress);

        $result = $task->result;

        $this->assertNotEmpty($result['sources'], 'Sources are the deliverable.');
        $this->assertSame('https://www.dian.gov.co/regimen-sas', $result['sources'][0]['url']);

        // Recoverable after the application is closed: the result is a row, not
        // something that was on a screen.
        $fetched = $this->actingAs($this->user)->getJson("/api/ai/tasks/{$taskId}");
        $fetched->assertOk()->assertJsonPath('task.status', AiTask::STATUS_SUCCEEDED);
        $this->assertSame($result['sources'][0]['url'], $fetched->json('task.result.sources.0.url'));
    }

    public function test_research_without_sources_is_reported_as_such_and_not_invented(): void
    {
        $this->fakeSearch([]);

        $id = $this->actingAs($this->user)->postJson('/api/ai/tasks/research', [
            'question' => 'Pregunta sin ninguna fuente posible.',
        ])->json('task.id');

        $this->work();

        $task = AiTask::query()->findOrFail($id);

        // Succeeded with an honest outcome. A failed task here would imply the
        // system broke; a summary here would mean a paragraph with no provenance.
        $this->assertSame(AiTask::STATUS_SUCCEEDED, $task->status);
        $this->assertSame('no_sources', $task->result['outcome']);
        $this->assertSame([], $task->result['sources']);
        $this->assertNull($task->result['summary']);
    }

    public function test_research_rejects_a_question_before_creating_anything(): void
    {
        $this->redisQueue();

        $this->actingAs($this->user)
            ->postJson('/api/ai/tasks/research', ['question' => '   '])
            ->assertStatus(422);

        $this->assertSame(0, AiTask::query()->count());
        $this->assertSame(0, $this->redisQueue()->size(self::QUEUE));
    }

    public function test_research_into_another_persons_conversation_is_refused(): void
    {
        $theirs = AiConversation::query()->create(['user_id' => $this->stranger->id, 'title' => 'Suya']);

        $this->actingAs($this->user)
            ->postJson('/api/ai/tasks/research', ['question' => 'Algo', 'conversation_id' => $theirs->id])
            ->assertNotFound();

        $this->assertSame(0, AiTask::query()->count());
    }

    public function test_an_unsupported_kind_is_refused_before_a_row_exists(): void
    {
        $service = app(AiTaskService::class);

        // A kind nothing implements must not leave a task behind to wonder about.
        $this->assertFalse($service->supports('something_made_up'));
        $this->assertSame(0, AiTask::query()->count());
    }

    /**
     * Fakes the search provider at the HTTP boundary.
     *
     * @param  list<array{title: string, url: string, snippet: string}>  $results
     */
    private function fakeSearch(array $results): void
    {
        config(['ai.web_search.base_url' => 'https://search.test', 'ai.web_search.timeout_seconds' => 5]);

        Http::fake([
            'search.test/*' => Http::response([
                'results' => array_map(
                    static fn (array $r): array => [
                        'title' => $r['title'],
                        'url' => $r['url'],
                        'content' => $r['snippet'],
                        'engine' => 'test',
                    ],
                    $results,
                ),
            ]),
            // The provider itself: never reached in the no-sources case, and
            // present so a summary path does not explode on configuration.
            '*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Resumen según la fuente [1].']]],
                'model' => 'fake-model',
            ]),
        ]);
    }

    // =====================================================================
    // T2 - document generation
    // =====================================================================

    public function test_document_generation_produces_a_real_downloadable_file(): void
    {
        $id = $this->actingAs($this->user)->postJson('/api/ai/tasks/document-generate', [
            'format' => 'csv',
            'filename' => 'reporte',
            // Spreadsheet formats take rows, not prose: the existing writer
            // expects JSON and refuses anything else, which is the right
            // refusal - it would otherwise emit a CSV with one cell.
            'content' => '[["Cliente","Saldo"],["MARIA LOPEZ",45000000]]',
            'conversation_id' => $this->conversation->id,
        ])->assertAccepted()->json('task.id');

        $this->work();

        $task = AiTask::query()->findOrFail($id);
        $this->assertSame(AiTask::STATUS_SUCCEEDED, $task->status, (string) $task->error);

        $artifact = AiArtifact::query()->findOrFail($task->result['artifact_id']);

        // A real file on disk, in private storage, with real bytes - not a
        // response that looks like a file.
        $this->assertSame($this->conversation->id, $artifact->conversation_id);
        $this->assertSame($task->id, $artifact->ai_task_id);
        Storage::disk('local')->assertExists($artifact->storage_path);
        $this->assertGreaterThan(0, $artifact->size_bytes);
        $this->assertStringContainsString('MARIA LOPEZ', Storage::disk('local')->get($artifact->storage_path));

        // And it downloads for its owner.
        $download = $this->actingAs($this->user)->get($task->result['download_url']);
        $download->assertOk();
        $this->assertStringContainsString('MARIA LOPEZ', $download->streamedContent() ?: $download->getContent());
    }

    public function test_a_generated_artifact_is_not_downloadable_by_anybody_else(): void
    {
        $id = $this->actingAs($this->user)->postJson('/api/ai/tasks/document-generate', [
            'format' => 'txt',
            'filename' => 'notas',
            'content' => 'Contenido privado de un cliente.',
        ])->assertAccepted()->json('task.id');

        $this->work();

        $artifactId = AiTask::query()->findOrFail($id)->result['artifact_id'];

        $this->actingAs($this->stranger)->get("/api/ai/artifacts/{$artifactId}/download")->assertNotFound();
    }

    public function test_generation_supports_every_approved_format_with_a_real_file(): void
    {
        // Every format the list claims, and each with the content shape that
        // format's writer actually accepts.
        foreach ([
            'txt' => 'Contenido de prueba.',
            'md' => '# Título\n\nContenido de prueba.',
            'csv' => '[["Cliente","Saldo"],["MARIA LOPEZ",45000000]]',
            'xlsx' => '[["Cliente","Saldo"],["MARIA LOPEZ",45000000]]',
            'pdf' => '<p>Contenido de prueba.</p>',
            'docx' => 'Contenido de prueba.',
        ] as $format => $content) {
            $id = $this->actingAs($this->user)->postJson('/api/ai/tasks/document-generate', [
                'format' => $format,
                'filename' => 'salida-'.$format,
                'content' => $content,
            ])->assertAccepted()->json('task.id');

            $this->work();

            $task = AiTask::query()->findOrFail($id);
            $this->assertSame(AiTask::STATUS_SUCCEEDED, $task->status, "{$format}: ".var_export($task->error, true));

            $artifact = AiArtifact::query()->findOrFail($task->result['artifact_id']);

            Storage::disk('local')->assertExists($artifact->storage_path);
            $this->assertGreaterThan(0, (int) $artifact->size_bytes, "El formato {$format} debe producir bytes reales.");
        }
    }

    public function test_generation_rejects_an_unapproved_format_before_creating_anything(): void
    {
        $this->redisQueue();

        $this->actingAs($this->user)
            ->postJson('/api/ai/tasks/document-generate', ['format' => 'exe', 'filename' => 'x', 'content' => 'y'])
            ->assertStatus(422);

        $this->assertSame(0, AiTask::query()->count());
        $this->assertSame(0, $this->redisQueue()->size(self::QUEUE));
    }

    public function test_generation_with_neither_content_nor_a_brief_is_refused(): void
    {
        $this->redisQueue();

        $this->actingAs($this->user)
            ->postJson('/api/ai/tasks/document-generate', ['format' => 'pdf', 'filename' => 'x'])
            ->assertStatus(422);

        $this->assertSame(0, AiTask::query()->count());
    }

    public function test_generation_reuses_the_approved_format_list(): void
    {
        $this->assertSame(['pdf', 'docx', 'xlsx', 'csv', 'md', 'txt'], GenerateDocumentTask::FORMATS);
    }
}
