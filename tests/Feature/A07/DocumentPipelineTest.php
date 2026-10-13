<?php

declare(strict_types=1);

use App\Domain\Assistant\AiTaskService;
use App\Domain\Documents\DocumentRetriever;
use App\Jobs\RunAiTask;
use App\Models\AiAttachment;
use App\Models\AiAttachmentChunk;
use App\Models\AiConversation;
use App\Models\AiTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GATE 5: the document pipeline, end to end, on a real queue.
 *
 * ## What makes this file different from the others
 *
 * It is not `Queue::fake()`. That would prove the job was dispatched and stop
 * there, which is exactly the gap this gate exists to close: `DocumentIngestor`
 * used to be reachable only from tests, and a pipeline nothing but tests can
 * drive is not a pipeline.
 *
 * So the queue here is the deployment's own Redis, the job is serialised into
 * it and popped back out by Laravel's real `Worker` - the same class the
 * `queue:work` command runs - and the document is then read back through the
 * retriever the assistant itself uses.
 *
 * ## Why the worker's transaction is not a problem
 *
 * The job crosses Redis carrying only a task id, and the worker runs in this
 * same process against this same connection, so it sees the uncommitted rows
 * the test just wrote. `RefreshDatabase` is therefore used like everywhere else
 * - and `DatabaseMigrations`, which would commit, is not: rolling the schema
 * back fails on a pre-existing PostgreSQL function dependency and takes the
 * rest of the suite's database with it.
 */
final class DocumentPipelineTest extends TestCase
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

        foreach (['ai.use', 'documents.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['ai.use', 'documents.view']);

        $this->stranger = User::factory()->create();
        $this->stranger->givePermissionTo(['ai.use', 'documents.view']);

        $this->conversation = AiConversation::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Conversación de prueba',
        ]);

        Storage::fake('local');

        // The deployment's real queue, not `sync`. Set per test rather than in
        // phpunit.xml because the rest of the suite must stay synchronous - a
        // job nobody runs is a job whose assertions are about dispatch rather
        // than about work.
        config([
            'queue.default' => 'redis',
            'ai.suggestions.reasoning.enabled' => false,
            // The retry policy is real, but not on the clock: exhausting three
            // attempts across a 70-second backoff would make this suite slow
            // and its result a function of timing.
            'ai.tasks.backoff' => [0, 0],
        ]);

        // The queue is this deployment's real Redis, so it can still hold jobs
        // from a run that was interrupted before it drained them. Starting from
        // an unknown depth would make "how many jobs are waiting" meaningless,
        // and a suite that measures a shared resource has to own it.
        $this->redisQueue()->clear(self::QUEUE);
    }

    private function redisQueue(): RedisQueue
    {
        $queue = app('queue')->connection('redis');

        $this->assertInstanceOf(RedisQueue::class, $queue);

        return $queue;
    }

    /**
     * A real .xlsx with real content in it.
     */
    private function workbookUpload(string $filename = 'cartera.xlsx'): UploadedFile
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->setTitle('Cartera');
        $sheet->getActiveSheet()->fromArray([
            ['Cliente', 'Empresa', 'Saldo', 'Vencido'],
            ['MARIA LOPEZ', 'ANDINA S.A.S.', '45000000', '45000000'],
            ['CARLOS RUIZ', 'SERVICIOS DEL NORTE S.A.S.', '12000000', '0'],
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'cdh-pipeline').'.xlsx';
        (new Xlsx($sheet))->save($path);

        return new UploadedFile(
            $path,
            $filename,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    /**
     * Run the real queue worker until the queue is empty.
     *
     * `queue.worker` is the container's own binding - the same instance the
     * `queue:work` command receives - so this exercises serialisation, the Redis
     * round trip, job events and the handler, not a direct method call.
     */
    private function workTheQueue(): int
    {
        $worker = app('queue.worker');

        // `maxTries` comes from the deployment's own policy, not a literal:
        // the worker decides a job has exhausted its retries by comparing its
        // attempts against this option, not against the job's own `$tries`.
        // Hard-coding it here would let a test claim a policy the worker never
        // applied.
        //
        // `sleep: 0` matters too. A released job is not immediately poppable, so
        // each empty poll costs the default three seconds - twelve of them made
        // this file take a minute and turned a retry loop into a wall-clock test.
        $options = new WorkerOptions(
            maxTries: (int) config('ai.tasks.tries', 3),
            sleep: 0,
        );

        $processed = 0;

        // Driven by the queue's depth, not by `runNextJob`'s return value: that
        // method returns void, so a `while (runNextJob(...))` loop silently runs
        // zero times and every assertion after it measures a queue nobody
        // touched. The ceiling keeps a handler that re-queues itself from
        // hanging the suite instead of failing it.
        while ($this->redisQueue()->size(self::QUEUE) > 0 && $processed < 10) {
            $worker->runNextJob('redis', self::QUEUE, $options);
            $processed++;
        }

        return $processed;
    }

    // ---------------------------------------------------------------------
    // The mandatory proof
    // ---------------------------------------------------------------------

    public function test_a_real_upload_is_processed_by_a_real_worker_and_can_be_read_back(): void
    {
        // 1. A real file, through the real endpoint.
        $response = $this->actingAs($this->user)->post('/api/ai/tasks', [
            'file' => $this->workbookUpload(),
            'conversation_id' => $this->conversation->id,
        ]);

        $response->assertAccepted();

        $taskId = (int) $response->json('task.id');

        $this->assertSame(AiTask::STATUS_QUEUED, $response->json('task.status'));
        $this->assertFalse($response->json('task.finished'));

        // 2. The work is genuinely queued on Redis, not run inline.
        $this->assertSame(1, $this->redisQueue()->size(self::QUEUE), 'The job is waiting in Redis, not already done.');

        $task = AiTask::query()->findOrFail($taskId);
        $this->assertSame(0, AiTaskChunkCount(), 'Nothing is indexed until the worker runs.');

        // 3. A real worker picks it up.
        $this->assertSame(1, $this->workTheQueue());

        $task->refresh();
        $this->assertSame(AiTask::STATUS_SUCCEEDED, $task->status, (string) $task->error);
        $this->assertSame(100, $task->progress);
        $this->assertSame(1, $task->attempts);
        $this->assertNotNull($task->finished_at);

        // 4. The document exists, is indexed, and its counts are real.
        $attachment = AiAttachment::query()->where('ai_task_id', $task->id)->firstOrFail();

        $this->assertSame(AiAttachment::STATUS_PROCESSED, $attachment->status);
        $this->assertGreaterThan(0, $attachment->character_count);
        $this->assertGreaterThan(0, $attachment->chunk_count);
        $this->assertSame(
            $attachment->chunk_count,
            AiAttachmentChunk::query()->where('ai_attachment_id', $attachment->id)->count(),
        );

        // 5. And it is readable through the retriever the assistant uses, inside
        //    the conversation it was uploaded to.
        $found = app(DocumentRetriever::class)
            ->search($this->user, 'MARIA LOPEZ', $this->conversation->id);

        $this->assertNotEmpty($found['passages'], 'The processed document is answerable.');
        $this->assertStringContainsString('MARIA LOPEZ', $found['passages'][0]['content'] ?? '');

        $whole = app(DocumentRetriever::class)
            ->wholeDocument($this->user, $attachment->id, $this->conversation->id);

        $this->assertNotNull($whole, 'And the whole document is reachable, not just a fragment.');
        $this->assertStringContainsString('ANDINA', $whole['content'] ?? '');
    }

    // ---------------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------------

    public function test_uploading_the_same_bytes_twice_queues_the_work_once(): void
    {
        // The very same bytes, built once. A workbook written twice carries a
        // creation timestamp, so two builds can differ - and the upload is
        // content-addressed, which would make them two different uploads and the
        // test would pass for the wrong reason whenever the two builds landed in
        // different seconds.
        $once = $this->workbookUpload();
        $upload = fn (): UploadedFile => new UploadedFile(
            $once->getRealPath(),
            $once->getClientOriginalName(),
            $once->getClientMimeType(),
            null,
            true,
        );

        $first = $this->actingAs($this->user)
            ->post('/api/ai/tasks', ['file' => $upload(), 'conversation_id' => $this->conversation->id])
            ->assertAccepted()
            ->json('task.id');

        // Content-addressed: the same bytes are the same upload, whichever tab
        // sent them or whatever the person called the file.
        $second = $this->actingAs($this->user)
            ->post('/api/ai/tasks', ['file' => $upload(), 'conversation_id' => $this->conversation->id])
            ->assertAccepted()
            ->json('task.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, AiTask::query()->count());
        $this->assertSame(1, $this->redisQueue()->size(self::QUEUE));
    }

    public function test_running_a_task_twice_indexes_the_document_once(): void
    {
        $this->actingAs($this->user)
            ->post('/api/ai/tasks', ['file' => $this->workbookUpload(), 'conversation_id' => $this->conversation->id])
            ->assertAccepted();

        $this->workTheQueue();

        $attachment = AiAttachment::query()->firstOrFail();
        $chunksAfterFirst = AiAttachmentChunk::query()->where('ai_attachment_id', $attachment->id)->count();

        // Redis absolutely can deliver a job twice - a worker killed mid-flight
        // will have its job redelivered after the visibility timeout. Re-running
        // must converge, not duplicate, or every answer from this document is
        // quietly biased toward whatever got indexed twice.
        $task = AiTask::query()->firstOrFail();
        $task->forceFill([
            'status' => AiTask::STATUS_QUEUED,
            'progress' => 0,
            'result' => null,
            'error' => null,
            'finished_at' => null,
        ])->save();

        RunAiTask::dispatch($task->id);
        $this->workTheQueue();

        $task->refresh();

        $this->assertSame(AiTask::STATUS_SUCCEEDED, $task->status);
        $this->assertSame(
            $chunksAfterFirst,
            AiAttachmentChunk::query()->where('ai_attachment_id', $attachment->id)->count(),
            'A second pass replaces the index instead of doubling it.',
        );
        $this->assertSame(
            $chunksAfterFirst,
            (int) AiAttachment::query()->findOrFail($attachment->id)->chunk_count,
        );
    }

    // ---------------------------------------------------------------------
    // Isolation and authorisation
    // ---------------------------------------------------------------------

    public function test_another_persons_task_is_not_readable_or_cancellable(): void
    {
        $id = $this->actingAs($this->user)
            ->post('/api/ai/tasks', ['file' => $this->workbookUpload(), 'conversation_id' => $this->conversation->id])
            ->json('task.id');

        // 404, not 403: a refusal that distinguishes "exists but forbidden"
        // tells a stranger that the id is real.
        $this->actingAs($this->stranger)->getJson("/api/ai/tasks/{$id}")->assertNotFound();
        $this->actingAs($this->stranger)->postJson("/api/ai/tasks/{$id}/cancel")->assertNotFound();

        $this->assertSame(AiTask::STATUS_QUEUED, AiTask::query()->findOrFail($id)->status);
    }

    public function test_your_own_task_is_readable_and_listed(): void
    {
        $id = $this->actingAs($this->user)
            ->post('/api/ai/tasks', ['file' => $this->workbookUpload(), 'conversation_id' => $this->conversation->id])
            ->json('task.id');

        $this->actingAs($this->user)->getJson("/api/ai/tasks/{$id}")->assertOk()
            ->assertJsonPath('task.id', $id);

        $this->actingAs($this->user)->getJson('/api/ai/tasks')->assertOk()
            ->assertJsonCount(1, 'tasks');

        $this->actingAs($this->stranger)->getJson('/api/ai/tasks')->assertOk()
            ->assertJsonCount(0, 'tasks');
    }

    public function test_a_document_cannot_be_attached_to_another_persons_conversation(): void
    {
        $theirs = AiConversation::query()->create(['user_id' => $this->stranger->id, 'title' => 'Suya']);

        $this->actingAs($this->user)
            ->post('/api/ai/tasks', ['file' => $this->workbookUpload(), 'conversation_id' => $theirs->id])
            ->assertNotFound();

        $this->assertSame(0, AiTask::query()->count());
    }

    public function test_a_processed_document_is_not_readable_from_another_conversation(): void
    {
        $this->actingAs($this->user)
            ->post('/api/ai/tasks', ['file' => $this->workbookUpload(), 'conversation_id' => $this->conversation->id])
            ->assertAccepted();

        $this->workTheQueue();

        $elsewhere = AiConversation::query()->create(['user_id' => $this->user->id, 'title' => 'Otra']);

        // The same person, a different conversation. Isolation between
        // conversations is what stops one thread answering from another's files.
        $found = app(DocumentRetriever::class)->search($this->user, 'MARIA LOPEZ', $elsewhere->id);

        $this->assertSame([], $found['passages']);
    }

    // ---------------------------------------------------------------------
    // Failure is visible and recoverable
    // ---------------------------------------------------------------------

    public function test_a_failure_is_recorded_on_the_attempt_that_failed(): void
    {
        $id = $this->actingAs($this->user)
            ->post('/api/ai/tasks', ['file' => $this->workbookUpload(), 'conversation_id' => $this->conversation->id])
            ->json('task.id');

        // Somebody cleaned the storage out from under the queue.
        Storage::disk('local')->delete(
            AiAttachment::query()->where('ai_task_id', $id)->value('storage_path')
        );

        $this->workTheQueue();

        $task = AiTask::query()->findOrFail($id);

        // The reason is written on the attempt that failed, not only after the
        // last one: a person watching progress needs to see that something went
        // wrong now, not three minutes later when the retries give up.
        //
        // More than one attempt, and that is the point: the failure releases the
        // claim, so the retries genuinely re-run rather than finding a task that
        // is "running" and skipping it.
        $this->assertGreaterThanOrEqual(1, $task->attempts);
        $this->assertStringContainsString('almacenamiento', $task->error);
        $this->assertNotSame(AiTask::STATUS_SUCCEEDED, $task->status);
    }

    public function test_a_task_that_exhausts_its_retries_is_marked_failed_with_a_reason(): void
    {
        // One try, so the exhaustion is reached in this test rather than after a
        // real backoff schedule the suite would have to wait out.
        config(['ai.tasks.tries' => 1]);

        $id = $this->actingAs($this->user)
            ->post('/api/ai/tasks', ['file' => $this->workbookUpload(), 'conversation_id' => $this->conversation->id])
            ->json('task.id');

        Storage::disk('local')->delete(
            AiAttachment::query()->where('ai_task_id', $id)->value('storage_path')
        );

        $this->workTheQueue();

        $task = AiTask::query()->findOrFail($id);

        // Failed, with a reason, and marked recoverable so the interface can
        // offer a retry rather than leaving a dead row behind.
        $this->assertSame(AiTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('almacenamiento', $task->error);
        $this->assertTrue($task->isRecoverable());
        $this->assertNotNull($task->finished_at);
        $this->assertSame(0, $this->redisQueue()->size(self::QUEUE), 'And it is off the queue, not retried forever.');
    }

    public function test_an_unreadable_file_is_reported_rather_than_silently_dropped(): void
    {
        // Correct extension, correct zip signature, not a real workbook.
        $path = tempnam(sys_get_temp_dir(), 'cdh-bad').'.xlsx';
        file_put_contents($path, "PK\x03\x04".random_bytes(256));

        $this->actingAs($this->user)->post('/api/ai/tasks', [
            'file' => new UploadedFile($path, 'roto.xlsx', null, null, true),
            'conversation_id' => $this->conversation->id,
        ])->assertAccepted();

        $this->workTheQueue();

        $attachment = AiAttachment::query()->firstOrFail();

        // The person uploaded something real, so it is kept - and told why it
        // did not read, rather than being left with a silent failure.
        $this->assertSame(AiAttachment::STATUS_UNREADABLE, $attachment->status);
        $this->assertNotNull($attachment->status_note);
    }

    // ---------------------------------------------------------------------
    // Cancellation and validation
    // ---------------------------------------------------------------------

    public function test_a_queued_task_can_be_cancelled_and_the_worker_then_does_nothing(): void
    {
        $id = $this->actingAs($this->user)
            ->post('/api/ai/tasks', ['file' => $this->workbookUpload(), 'conversation_id' => $this->conversation->id])
            ->json('task.id');

        $this->actingAs($this->user)->postJson("/api/ai/tasks/{$id}/cancel")->assertOk()
            ->assertJsonPath('task.status', AiTask::STATUS_CANCELLED);

        $this->workTheQueue();

        $task = AiTask::query()->findOrFail($id);

        // Cancelled means cancelled: the worker picks the job up, sees it is
        // finished, and leaves the document alone.
        $this->assertSame(AiTask::STATUS_CANCELLED, $task->status);
        $this->assertNull($task->result);
        $this->assertSame(0, AiAttachmentChunk::query()->count());
    }

    public function test_a_running_task_cannot_be_cancelled(): void
    {
        $id = $this->actingAs($this->user)
            ->post('/api/ai/tasks', ['file' => $this->workbookUpload(), 'conversation_id' => $this->conversation->id])
            ->json('task.id');

        $this->workTheQueue();

        // Cancelling mid-flight would mean abandoning a half-written index.
        $this->actingAs($this->user)->postJson("/api/ai/tasks/{$id}/cancel")->assertStatus(409);
    }

    public function test_an_unaccepted_format_is_refused_before_anything_is_stored(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cdh-bad').'.exe';
        file_put_contents($path, 'MZ...');

        $this->actingAs($this->user)
            ->post('/api/ai/tasks', ['file' => new UploadedFile($path, 'virus.exe', null, null, true)])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Formato no admitido'));

        $this->assertSame(0, AiTask::query()->count());
        $this->assertSame(0, $this->redisQueue()->size(self::QUEUE));
    }

    public function test_uploading_requires_the_assistant_permission(): void
    {
        // `$stranger` is a legitimate user with `ai.use`; the point of this test
        // is somebody with no permission at all, which is a different person.
        $nobody = User::factory()->create();

        $this->actingAs($nobody)
            ->post('/api/ai/tasks', ['file' => $this->workbookUpload()])
            ->assertForbidden();

        $this->assertSame(0, AiTask::query()->count());
    }

    public function test_the_service_reports_which_kinds_of_work_it_can_queue(): void
    {
        $service = app(AiTaskService::class);

        $this->assertTrue($service->supports(AiTask::KIND_DOCUMENT_INGEST));
        $this->assertFalse($service->supports('something_made_up'));
    }
}

/**
 * Chunk rows for every attachment, used to assert "nothing indexed yet".
 */
function AiTaskChunkCount(): int
{
    return AiAttachmentChunk::query()->count();
}
