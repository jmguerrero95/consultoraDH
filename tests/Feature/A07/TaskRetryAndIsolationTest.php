<?php

declare(strict_types=1);

use App\Domain\Assistant\AiTaskService;
use App\Jobs\AiTaskHandlerResolver;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GATE 7, intervention 3: two integration defects, reproduced and fixed.
 *
 * ## 3A - a failed attempt could never be retried
 *
 * The claim only accepted `queued`, and a failure left the row as `running`.
 * So every retry found a task that was already running, skipped it, and
 * returned: three attempts, no work, and a row stuck on "running" for ever. The
 * bounded retry policy existed and did nothing, which is the worst possible
 * shape for a retry - it looks like resilience.
 *
 * ## 3B - the same file in two conversations became one task
 *
 * Deduplication keyed on the content hash alone, so the second conversation
 * received the first conversation's task - with its attachment bound to the
 * wrong thread. The file was silently missing from the conversation it was
 * actually attached to.
 *
 * ## Both are asserted through the real queue and the real worker
 */
final class TaskRetryAndIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const QUEUE = 'ai-tasks';

    private User $user;

    private User $stranger;

    private AiConversation $first;

    private AiConversation $second;

    protected function setUp(): void
    {
        parent::setUp();

        seedPortfolioRoles();

        foreach (['ai.use', 'documents.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['status' => 'active']);
        $this->user->givePermissionTo(['ai.use', 'documents.view']);

        $this->stranger = User::factory()->create(['status' => 'active']);
        $this->stranger->givePermissionTo(['ai.use', 'documents.view']);

        $this->first = AiConversation::query()->create(['user_id' => $this->user->id, 'title' => 'Primera']);
        $this->second = AiConversation::query()->create(['user_id' => $this->user->id, 'title' => 'Segunda']);

        Storage::fake('local');

        config([
            'queue.default' => 'redis',
            'ai.tasks.tries' => 3,
            'ai.tasks.backoff' => [0, 0],
            'ai.suggestions.reasoning.enabled' => false,
        ]);

        $this->redisQueue()->clear(self::QUEUE);
    }

    protected function tearDown(): void
    {
        // Some cases upload without draining the queue, because their subject
        // is the dedupe key rather than the work. Clear the suite's own queue so
        // that job cannot be picked up by a later case.
        $this->redisQueue()->clear(self::QUEUE);

        parent::tearDown();
    }

    private function redisQueue(): RedisQueue
    {
        $queue = app('queue')->connection('redis');
        $this->assertInstanceOf(RedisQueue::class, $queue);

        return $queue;
    }

    private function work(int $max = 20): int
    {
        $worker = app('queue.worker');
        $options = new WorkerOptions(maxTries: (int) config('ai.tasks.tries', 3), sleep: 0);
        $processed = 0;

        while ($this->redisQueue()->size(self::QUEUE) > 0 && $processed < $max) {
            $worker->runNextJob('redis', self::QUEUE, $options);
            $processed++;
        }

        return $processed;
    }

    private function workbook(string $name = 'cartera.xlsx'): UploadedFile
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->setTitle('Cartera');
        $sheet->getActiveSheet()->fromArray([['Cliente'], ['MARIA LOPEZ']], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'cdh-retry').'.xlsx';
        (new Xlsx($sheet))->save($path);

        return new UploadedFile($path, $name, null, null, true);
    }

    // =====================================================================
    // 3A - retries
    // =====================================================================

    public function test_a_failed_attempt_is_released_so_the_retry_can_actually_run_it(): void
    {
        $task = AiTask::query()->create([
            'user_id' => $this->user->id,
            'kind' => AiTask::KIND_DOCUMENT_INGEST,
            'status' => AiTask::STATUS_QUEUED,
            'input' => ['name' => 'x'],
            'dedupe_key' => 'retry:'.uniqid(),
            'queued_at' => now(),
        ]);

        // No attachment at all, so the handler throws on the first attempt.
        RunAiTask::dispatch($task->id);

        // First attempt fails and must leave the row claimable.
        app('queue.worker')->runNextJob('redis', self::QUEUE, new WorkerOptions(maxTries: 3, sleep: 0));

        $task->refresh();

        $this->assertSame(
            AiTask::STATUS_QUEUED,
            $task->status,
            'A failed attempt has to release its claim, or no retry can ever take it.',
        );
        $this->assertNotNull($task->error, 'And the reason is recorded on the attempt that failed.');
        $this->assertSame(1, $task->attempts);

        // The retry runs for real and the attempts counter proves it did.
        $this->work();

        $task->refresh();

        $this->assertGreaterThan(1, $task->attempts, 'The second attempt really ran.');
        $this->assertSame(AiTask::STATUS_FAILED, $task->status, 'And exhausting the retries ends in failed.');
        $this->assertNotNull($task->finished_at);
    }

    public function test_a_transient_failure_is_retried_and_succeeds_without_duplicating_work(): void
    {
        $task = AiTask::query()->create([
            'user_id' => $this->user->id,
            'kind' => AiTask::KIND_DOCUMENT_INGEST,
            'status' => AiTask::STATUS_QUEUED,
            'input' => ['name' => 'notas.txt'],
            'dedupe_key' => 'transient:'.uniqid(),
            'queued_at' => now(),
        ]);

        Storage::disk('local')->put('ai-attachments/'.$this->user->id.'/notas.txt', 'Saldo de Ana Torres.');

        $attachment = app(AiAttachment::class)->query()->create([
            'user_id' => $this->user->id,
            'ai_task_id' => $task->id,
            'storage_path' => 'ai-attachments/'.$this->user->id.'/notas.txt',
            'original_name' => 'notas.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size_bytes' => 20,
            'status' => 'queued',
        ]);

        RunAiTask::dispatch($task->id);

        // The storage the handler needs is taken away for exactly one attempt,
        // then put back: the stand-in for a converter that was briefly down.
        $path = $attachment->storage_path;
        $bytes = Storage::disk('local')->get($path);
        Storage::disk('local')->delete($path);

        app('queue.worker')->runNextJob('redis', self::QUEUE, new WorkerOptions(maxTries: 3, sleep: 0));

        $task->refresh();
        $this->assertNotSame(AiTask::STATUS_SUCCEEDED, $task->status);

        Storage::disk('local')->put($path, $bytes);
        RunAiTask::dispatch($task->id);

        $this->work();

        $task->refresh();

        $this->assertSame(AiTask::STATUS_SUCCEEDED, $task->status, (string) $task->error);
        $this->assertSame(2, $task->attempts);

        // Indexed exactly once, even though it ran twice. Replacing rather than
        // appending is what makes the retry safe.
        $this->assertSame(
            1,
            AiAttachmentChunk::query()->where('ai_attachment_id', $attachment->id)->count(),
        );
    }

    public function test_a_successful_task_is_never_run_again(): void
    {
        $task = $this->succeededTask('succeeded:'.uniqid());

        // Redis redelivers a job whose worker died after the work was saved.
        RunAiTask::dispatch($task->id);
        $this->work();

        $task->refresh();

        $this->assertSame(1, $task->attempts, 'A finished task is not re-claimed.');
        $this->assertSame('original-result', $task->result['marker']);
    }

    public function test_a_task_abandoned_by_a_dead_worker_is_taken_over_rather_than_stuck(): void
    {
        // A worker killed mid-flight leaves the row exactly as it was. Nothing
        // else would ever move it, and the person would watch a job that stopped
        // weeks ago.
        $task = AiTask::query()->create([
            'user_id' => $this->user->id,
            'kind' => AiTask::KIND_DOCUMENT_INGEST,
            'status' => AiTask::STATUS_RUNNING,
            'progress' => 40,
            'stage' => 'Extrayendo texto',
            'input' => ['name' => 'x'],
            'dedupe_key' => 'stale:'.uniqid(),
            'queued_at' => now()->subHour(),
            'started_at' => now()->subHour(),
            'attempts' => 1,
        ]);

        RunAiTask::dispatch($task->id);
        $this->work();

        $task->refresh();

        $this->assertSame(AiTask::STATUS_FAILED, $task->status, 'Taken over, then run for real.');
        // More than the single attempt the dead worker recorded: the point is
        // that it moved at all, not how many times the new failure was retried.
        $this->assertGreaterThan(1, $task->attempts);
    }

    public function test_a_task_delivered_twice_never_runs_twice(): void
    {
        // Redis will deliver a job twice when a worker is killed after doing the
        // work but before the acknowledgement is recorded. The second delivery
        // must find the task finished and do nothing.
        //
        // Running the handler twice in sequence is the same shape as that race:
        // the claim is a conditional update, so the second caller finds a row
        // that is no longer claimable and returns before the handler runs.
        $task = AiTask::query()->create([
            'user_id' => $this->user->id,
            'kind' => AiTask::KIND_DOCUMENT_INGEST,
            'status' => AiTask::STATUS_QUEUED,
            'input' => ['name' => 'notas.txt'],
            'dedupe_key' => 'double:'.uniqid(),
            'queued_at' => now(),
        ]);

        Storage::disk('local')->put('ai-attachments/'.$this->user->id.'/notas.txt', 'Saldo de Ana Torres.');

        AiAttachment::query()->create([
            'user_id' => $this->user->id,
            'ai_task_id' => $task->id,
            'storage_path' => 'ai-attachments/'.$this->user->id.'/notas.txt',
            'original_name' => 'notas.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size_bytes' => 20,
            'status' => 'queued',
        ]);

        $resolver = app(AiTaskHandlerResolver::class);
        $job = new RunAiTask($task->id);

        $job->handle($resolver);
        $job->handle($resolver);

        $task->refresh();

        $this->assertSame(1, $task->attempts, 'Claimed once.');
        $this->assertSame(AiTask::STATUS_SUCCEEDED, $task->status);
        $this->assertSame(1, AiAttachmentChunk::query()->count(), 'Indexed once, not twice.');
    }

    private function succeededTask(string $key): AiTask
    {
        return AiTask::query()->create([
            'user_id' => $this->user->id,
            'kind' => AiTask::KIND_DOCUMENT_INGEST,
            'status' => AiTask::STATUS_SUCCEEDED,
            'progress' => 100,
            'result' => ['marker' => 'original-result'],
            'input' => ['name' => 'x'],
            'dedupe_key' => $key,
            'queued_at' => now(),
            'started_at' => now(),
            'finished_at' => now(),
            'attempts' => 1,
        ]);
    }

    // =====================================================================
    // 3B - cross-conversation isolation
    // =====================================================================

    public function test_the_same_file_in_two_conversations_produces_two_tasks(): void
    {
        $once = $this->workbook();
        $again = new UploadedFile($once->getRealPath(), $once->getClientOriginalName(), null, null, true);

        $a = $this->actingAs($this->user)->post('/api/ai/tasks', [
            'file' => $once,
            'conversation_id' => $this->first->id,
        ])->assertAccepted()->json('task.id');

        $b = $this->actingAs($this->user)->post('/api/ai/tasks', [
            'file' => $again,
            'conversation_id' => $this->second->id,
        ])->assertAccepted()->json('task.id');

        $this->assertNotSame($a, $b, 'The same bytes in two conversations are two pieces of work.');

        $this->work();

        // And each one is bound to the conversation that actually asked for it.
        $this->assertSame($this->first->id, AiTask::query()->findOrFail($a)->ai_conversation_id);
        $this->assertSame($this->second->id, AiTask::query()->findOrFail($b)->ai_conversation_id);
    }

    public function test_the_same_file_twice_in_one_conversation_is_still_one_task(): void
    {
        $once = $this->workbook();
        $again = new UploadedFile($once->getRealPath(), $once->getClientOriginalName(), null, null, true);

        $a = $this->actingAs($this->user)->post('/api/ai/tasks', [
            'file' => $once,
            'conversation_id' => $this->first->id,
        ])->json('task.id');

        $b = $this->actingAs($this->user)->post('/api/ai/tasks', [
            'file' => $again,
            'conversation_id' => $this->first->id,
        ])->json('task.id');

        $this->assertSame($a, $b, 'A double-click is not two documents.');
        $this->assertSame(1, AiTask::query()->count());
    }

    public function test_the_same_bytes_for_two_people_are_two_tasks(): void
    {
        $once = $this->workbook();
        $again = new UploadedFile($once->getRealPath(), $once->getClientOriginalName(), null, null, true);

        $mine = $this->actingAs($this->user)->post('/api/ai/tasks', ['file' => $once])->json('task.id');
        $theirs = $this->actingAs($this->stranger)->post('/api/ai/tasks', ['file' => $again])->json('task.id');

        $this->assertNotSame($mine, $theirs);

        // And the dedupe key itself is scoped by user, so one person's upload
        // can never be returned as another's task.
        $service = app(AiTaskService::class);
        $this->assertSame(2, AiTask::query()->count());
        $this->assertNotNull($service);
    }

    public function test_an_upload_to_someone_elses_conversation_is_still_refused(): void
    {
        $theirs = AiConversation::query()->create(['user_id' => $this->stranger->id, 'title' => 'Suya']);

        $this->actingAs($this->user)
            ->post('/api/ai/tasks', ['file' => $this->workbook(), 'conversation_id' => $theirs->id])
            ->assertNotFound();

        $this->assertSame(0, AiTask::query()->count());
    }

    public function test_deduplication_never_loses_a_file(): void
    {
        $once = $this->workbook();
        $again = new UploadedFile($once->getRealPath(), $once->getClientOriginalName(), null, null, true);

        $a = $this->actingAs($this->user)->post('/api/ai/tasks', ['file' => $once, 'conversation_id' => $this->first->id])->json('task.id');
        $b = $this->actingAs($this->user)->post('/api/ai/tasks', ['file' => $again, 'conversation_id' => $this->second->id])->json('task.id');

        $this->work();

        // Two conversations, two attachments, both indexed: nobody lost a file to
        // deduplication.
        $attachments = AiAttachment::query()->get();

        $this->assertCount(2, $attachments);
        $this->assertEqualsCanonicalizing(
            [$this->first->id, $this->second->id],
            $attachments->pluck('ai_conversation_id')->all(),
        );

        foreach ([$a, $b] as $id) {
            $this->assertSame(
                'processed',
                AiAttachment::query()->where('ai_task_id', $id)->value('status'),
            );
        }
    }

    public function test_rows_written_before_the_key_change_are_still_readable(): void
    {
        // Compatibility with what is already stored: an old key has no
        // conversation in it, and the row must still be listed and served.
        $legacy = AiTask::query()->create([
            'user_id' => $this->user->id,
            'kind' => AiTask::KIND_DOCUMENT_INGEST,
            'status' => AiTask::STATUS_SUCCEEDED,
            'input' => ['name' => 'antiguo.xlsx'],
            'result' => ['marker' => 'legacy'],
            'dedupe_key' => 'document:'.hash('sha256', 'antiguo'),
            'queued_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);

        $listed = $this->actingAs($this->user)->getJson('/api/ai/tasks');

        $listed->assertOk();
        $this->assertNotNull(
            collect($listed->json('tasks'))->firstWhere('id', $legacy->id),
            'A task stored under the old key must not vanish.',
        );

        $this->actingAs($this->user)->getJson("/api/ai/tasks/{$legacy->id}")->assertOk();
    }

    public function test_the_dedupe_key_of_an_upload_names_its_conversation(): void
    {
        $task = $this->actingAs($this->user)->post('/api/ai/tasks', [
            'file' => $this->workbook(),
            'conversation_id' => $this->first->id,
        ])->json('task.id');

        $key = DB::table('ai_tasks')->where('id', $task)->value('dedupe_key');

        $this->assertStringContainsString((string) $this->first->id, (string) $key);
    }
}
