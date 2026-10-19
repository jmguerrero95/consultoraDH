<?php

declare(strict_types=1);

use App\Domain\Users\UserStatus;
use App\Events\AiTaskCompleted;
use App\Jobs\RunAiTask;
use App\Models\AiAttachment;
use App\Models\AiTask;
use App\Models\PushNotificationDelivery;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GATE 6: finishing a background job reaches a phone.
 *
 * ## What "reaches a phone" can and cannot mean here
 *
 * There is no subscribed browser in this environment, so what is proven is the
 * half that can be: that a real job, run for real, ends in a call to the push
 * service with the right recipient and a payload that is safe to put on a lock
 * screen. Delivery to an actual device is a separate claim and is reported
 * separately.
 *
 * The push transport itself is faked. Faking it is not a shortcut around the
 * thing being tested - the thing being tested is *that the service is reached*,
 * and a real Web Push handshake would prove only that the network works.
 *
 * ## The security assertions are the point
 *
 * A completion notification is one of the few things this system sends to a
 * device that may not be in the owner's hands, so most of this file is about
 * what it must never contain and whom it must never reach.
 */
final class TaskCompletionPushTest extends TestCase
{
    use RefreshDatabase;

    private const QUEUE = 'ai-tasks';

    private User $user;

    private User $stranger;

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

        config([
            'queue.default' => 'redis',
            'ai.tasks.tries' => 1,
            'ai.tasks.backoff' => [0],
            'ai.suggestions.reasoning.enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        $this->redisQueue()->clear(self::QUEUE);

        parent::tearDown();
    }

    private function redisQueue(): RedisQueue
    {
        $queue = app('queue')->connection('redis');
        $this->assertInstanceOf(RedisQueue::class, $queue);
        $queue->clear(self::QUEUE);

        return $queue;
    }

    /**
     * Record a completed task without going through an upload.
     *
     * The upload path is covered by `DocumentPipelineTest`; what matters here is
     * the notification, and running it from a real worker would only add the
     * parsing time.
     */
    private function completedTask(User $owner, string $kind = AiTask::KIND_DOCUMENT_INGEST): AiTask
    {
        $this->redisQueue();

        return AiTask::query()->create([
            'user_id' => $owner->id,
            'kind' => $kind,
            'status' => AiTask::STATUS_SUCCEEDED,
            'progress' => 100,
            'stage' => 'Listo',
            'input' => ['name' => 'cartera-enero.xlsx'],
            'result' => ['attachment_id' => 7, 'characters' => 12000],
            'dedupe_key' => 'test:'.uniqid(),
            'queued_at' => now(),
            'finished_at' => now(),
        ]);
    }

    /**
     * A push service that records what it was asked to send.
     */
    private function fakePush(): MockInterface
    {
        $mock = Mockery::mock(WebPushService::class);
        $mock->shouldReceive('sendNotification')->andReturnNull();

        $this->app->instance(WebPushService::class, $mock);

        return $mock;
    }

    // ---------------------------------------------------------------------
    // The connection
    // ---------------------------------------------------------------------

    public function test_a_finished_job_reaches_the_push_service(): void
    {
        $mock = $this->fakePush();
        $task = $this->completedTask($this->user);

        AiTaskCompleted::dispatch($task->id);

        // The mandatory claim: a real event, handled by the real listener, ends
        // in the real service being called for the person who owns the work.
        $mock->shouldHaveReceived('sendNotification')
            ->once()
            ->withArgs(function (User $recipient, string $title, string $body, array $data) use ($task): bool {
                return $recipient->id === $this->user->id
                    && $title === 'Tu trabajo terminó'
                    && $body !== ''
                    && ($data['task_id'] ?? null) === $task->id;
            });
    }

    public function test_the_worker_announces_completion_rather_than_sending_directly(): void
    {
        // The worker announces a fact; notifying is somebody else's job. Proven
        // by running a real job and checking the event was raised - and, by the
        // absence of any push here, that the worker does not decide to interrupt
        // a phone on its own.
        Event::fake([AiTaskCompleted::class]);

        Storage::fake('local');
        Storage::disk('local')->put('ai-attachments/1/notas.txt', 'Saldo pendiente de Ana Torres.');

        $task = AiTask::query()->create([
            'user_id' => $this->user->id,
            'kind' => AiTask::KIND_DOCUMENT_INGEST,
            'status' => AiTask::STATUS_QUEUED,
            'input' => ['name' => 'notas.txt'],
            'dedupe_key' => 'test:'.uniqid(),
            'queued_at' => now(),
        ]);

        AiAttachment::query()->create([
            'user_id' => $this->user->id,
            'ai_task_id' => $task->id,
            'storage_path' => 'ai-attachments/1/notas.txt',
            'original_name' => 'notas.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size_bytes' => 31,
            'status' => 'queued',
        ]);

        $this->redisQueue();
        RunAiTask::dispatch($task->id);

        app('queue.worker')->runNextJob(
            'redis',
            self::QUEUE,
            new WorkerOptions(maxTries: 1, sleep: 0),
        );

        $task->refresh();

        $this->assertSame(AiTask::STATUS_SUCCEEDED, $task->status, (string) $task->error);
        Event::assertDispatched(AiTaskCompleted::class, fn (AiTaskCompleted $e) => $e->taskId === $task->id);
    }

    // ---------------------------------------------------------------------
    // Deduplication
    // ---------------------------------------------------------------------

    public function test_the_same_job_is_never_announced_twice_to_the_same_person(): void
    {
        $mock = $this->fakePush();
        $task = $this->completedTask($this->user);

        AiTaskCompleted::dispatch($task->id);
        AiTaskCompleted::dispatch($task->id);
        AiTaskCompleted::dispatch($task->id);

        $mock->shouldHaveReceived('sendNotification')->once();

        $this->assertSame(1, PushNotificationDelivery::query()->count());
    }

    public function test_two_people_waiting_on_two_jobs_are_told_about_their_own(): void
    {
        $mock = $this->fakePush();

        $mine = $this->completedTask($this->user);
        $theirs = $this->completedTask($this->stranger);

        AiTaskCompleted::dispatch($mine->id);
        AiTaskCompleted::dispatch($theirs->id);

        $mock->shouldHaveReceived('sendNotification')->twice();
        $this->assertSame(2, PushNotificationDelivery::query()->count());
    }

    public function test_a_duplicate_dispatch_is_refused_by_the_database_not_by_a_check(): void
    {
        // The guarantee is the unique index. Proven by going around the listener
        // and racing the insert directly: the second one cannot land.
        $task = $this->completedTask($this->user);
        $this->fakePush();

        AiTaskCompleted::dispatch($task->id);

        $this->expectException(UniqueConstraintViolationException::class);

        PushNotificationDelivery::query()->create([
            'user_id' => $this->user->id,
            'notification_type' => 'ai_task_completed',
            'dedupe_key' => 'ai_task_completed:'.$task->id,
            'sent_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------------
    // Who is not told
    // ---------------------------------------------------------------------

    public function test_nobody_else_is_told_about_your_job(): void
    {
        $mock = $this->fakePush();
        $task = $this->completedTask($this->user);

        AiTaskCompleted::dispatch($task->id);

        $sentTo = [];
        $mock->shouldHaveReceived('sendNotification')->once()->withArgs(
            function (User $recipient) use (&$sentTo): bool {
                $sentTo[] = $recipient->id;

                return true;
            }
        );

        $this->assertSame([$this->user->id], $sentTo);
    }

    public function test_a_deactivated_person_is_not_told(): void
    {
        $mock = $this->fakePush();
        $this->user->update(['status' => UserStatus::Inactive->value]);

        $task = $this->completedTask($this->user);
        AiTaskCompleted::dispatch($task->id);

        $mock->shouldNotHaveReceived('sendNotification');
    }

    public function test_nothing_is_sent_for_work_that_did_not_succeed(): void
    {
        $mock = $this->fakePush();

        $failed = $this->completedTask($this->user);
        $failed->update(['status' => AiTask::STATUS_FAILED, 'error' => 'No se pudo leer.']);

        AiTaskCompleted::dispatch($failed->id);

        // A person learns about a failure by looking at it. Interrupting a
        // phone about something that went wrong is how you train people to
        // dismiss notifications without reading them.
        $mock->shouldNotHaveReceived('sendNotification');
    }

    public function test_nothing_is_sent_for_a_task_that_no_longer_exists(): void
    {
        $mock = $this->fakePush();

        AiTaskCompleted::dispatch(999999);

        $mock->shouldNotHaveReceived('sendNotification');
    }

    // ---------------------------------------------------------------------
    // What the payload is allowed to contain
    // ---------------------------------------------------------------------

    public function test_the_notification_contains_no_document_detail(): void
    {
        $mock = $this->fakePush();
        $task = $this->completedTask($this->user);

        AiTaskCompleted::dispatch($task->id);

        $captured = null;
        $mock->shouldHaveReceived('sendNotification')->once()->withArgs(
            function (User $r, string $title, string $body, array $data) use (&$captured): bool {
                $captured = compact('title', 'body', 'data');

                return true;
            }
        );

        // A lock screen is public. Neither the file's name nor any figure from
        // it may appear, or the person holding the phone learns something about
        // a client's debt.
        $this->assertStringNotContainsString('cartera', $captured['title']);
        $this->assertStringNotContainsString('xlsx', $captured['body']);
        $this->assertStringNotContainsString('cartera', $captured['body']);
        $this->assertStringNotContainsString('12000', json_encode($captured['data']));

        $this->assertSame(
            ['type', 'task_id', 'url'],
            array_keys($captured['data']),
            'Ids and a link, nothing else.',
        );
    }

    public function test_the_deep_link_is_relative_and_authorised_on_arrival(): void
    {
        $mock = $this->fakePush();
        $task = $this->completedTask($this->user);

        AiTaskCompleted::dispatch($task->id);

        $captured = null;
        $mock->shouldHaveReceived('sendNotification')->once()->withArgs(
            function (User $r, string $t, string $b, array $data) use (&$captured): bool {
                $captured = $data;

                return true;
            }
        );

        $this->assertSame('/asistente?task='.$task->id, $captured['url']);
        $this->assertStringStartsNotWith('http', $captured['url']);

        // And the thing it opens really is refused for somebody else, which is
        // what makes an edited payload useless.
        $this->assertSame(
            404,
            $this->actingAs($this->stranger)
                ->getJson('/api/ai/tasks/'.$task->id)
                ->status()
        );
    }

    // ---------------------------------------------------------------------
    // Subscriptions
    // ---------------------------------------------------------------------

    public function test_a_retired_subscription_is_never_written_to_again(): void
    {
        $service = new WebPushService;

        $live = PushSubscription::query()->create([
            'user_id' => $this->user->id,
            'endpoint_hash' => hash('sha256', 'endpoint-live'),
            'endpoint' => encrypt('https://push.test/live'),
            'p256dh' => encrypt('k'),
            'auth' => encrypt('a'),
        ]);

        $gone = PushSubscription::query()->create([
            'user_id' => $this->user->id,
            'endpoint_hash' => hash('sha256', 'endpoint-gone'),
            'endpoint' => encrypt('https://push.test/gone'),
            'p256dh' => encrypt('k'),
            'auth' => encrypt('a'),
            'failed_at' => now(),
        ]);

        $eligible = $service->activeSubscriptions($this->user)->pluck('id');

        // A browser that said "gone" is skipped forever. Retrying it is latency
        // on a notification that cannot arrive, and the endpoint will not come
        // back - but if it were retried, one bad endpoint would hold up the one
        // device that could have received it.
        $this->assertTrue($eligible->contains($live->id));
        $this->assertFalse($eligible->contains($gone->id));
    }

    public function test_a_retired_subscription_belongs_only_to_its_own_owner(): void
    {
        $service = new WebPushService;

        $mine = PushSubscription::query()->create([
            'user_id' => $this->user->id,
            'endpoint_hash' => hash('sha256', 'mine'),
            'endpoint' => encrypt('https://push.test/mine'),
            'p256dh' => encrypt('k'),
            'auth' => encrypt('a'),
        ]);

        PushSubscription::query()->create([
            'user_id' => $this->stranger->id,
            'endpoint_hash' => hash('sha256', 'theirs'),
            'endpoint' => encrypt('https://push.test/theirs'),
            'p256dh' => encrypt('k'),
            'auth' => encrypt('a'),
        ]);

        $eligible = $service->activeSubscriptions($this->user)->pluck('id');

        $this->assertSame([$mine->id], $eligible->all());
    }
}
