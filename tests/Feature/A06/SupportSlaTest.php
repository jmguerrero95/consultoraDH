<?php

declare(strict_types=1);

use App\Domain\Support\Events\SupportSlaEventEmitted;
use App\Domain\Support\SupportConversationStatus;
use App\Jobs\ScanSupportSla;
use App\Models\Client;
use App\Models\SupportConversation;
use App\Models\SupportQueue;
use App\Models\SupportQueueMember;
use App\Models\SupportSlaEvent;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * R2-15 and R2-16 — the SLA lifecycle.
 *
 * ## What "correct lifecycle" has to mean
 *
 * A due date is only useful if it tracks the state of the conversation it
 * measures. The obligations are:
 *
 *   * a client message starts a first-response clock;
 *   * a visible staff reply satisfies it and starts a next-response clock;
 *   * an **internal note** satisfies neither — it is not a reply to the client,
 *     so letting it stop the clock would mean an agent could keep a conversation
 *     inside its SLA forever by typing into a field nobody sees;
 *   * resolving and closing stop the clocks;
 *   * reopening starts them again, because the work is live once more.
 *
 * The scanner must then report each breach and warning exactly once, and must
 * not report on conversations that are already finished.
 */
uses()->group('A06');
uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPortfolioRoles();
});

function slaQueue(array $overrides = []): SupportQueue
{
    return SupportQueue::factory()->create(array_merge([
        'first_response_minutes' => 60,
        'next_response_minutes' => 120,
        'resolution_minutes' => 1440,
        'warning_minutes_before' => 30,
        'active' => true,
    ], $overrides));
}

function slaConversation(SupportQueue $queue, array $overrides = []): SupportConversation
{
    return SupportConversation::factory()->create(array_merge([
        'queue_id' => $queue->id,
        'client_id' => Client::factory()->create()->id,
        'status' => 'waiting_staff',
    ], $overrides));
}

function staffMember(SupportQueue $queue): User
{
    $staff = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    SupportQueueMember::create(['queue_id' => $queue->id, 'user_id' => $staff->id]);

    return $staff;
}

/* -------------------------------------------------------------------------- */
/* | | R2-15 — the clocks                                                          *  */
/* -------------------------------------------------------------------------- */

test('a client message starts a first response clock', function (): void {
    $queue = slaQueue();

    // A persisted conversation: the action re-reads it under a row lock, so an
    // unsaved instance would not survive to be inspected afterwards.
    $conversation = slaConversation($queue, [
        'status' => 'waiting_staff',
        'first_response_due_at' => null,
    ]);

    $clientUser = User::factory()->create([
        'account_type' => 'client',
        'status' => 'active',
        'client_id' => $conversation->client_id,
    ]);

    app(App\Domain\Support\Actions\AppendClientMessage::class)->execute(
        $conversation,
        'Necesito ayuda con mi factura.',
        $clientUser,
    );

    expect($conversation->fresh()->first_response_due_at)->not->toBeNull();
})->group('R2-15');

test('a visible staff reply satisfies the first clock and starts the next one', function (): void {
    $queue = slaQueue();
    $staff = staffMember($queue);
    $client = Client::factory()->create();

    $conversation = slaConversation($queue, ['client_id' => $client->id]);

    app(App\Domain\Support\Actions\AppendStaffReply::class)->execute(
        $conversation,
        'Ya le estamos mirando.',
        $staff,
    );

    $fresh = $conversation->fresh();

    expect($fresh->first_responded_at)->not->toBeNull()
        // The next clock runs from the reply, not from the original question.
        ->and($fresh->next_staff_response_due_at)->not->toBeNull();
})->group('R2-15');

test('an internal note does not satisfy the first response clock', function (): void {
    $queue = slaQueue();
    $staff = staffMember($queue);

    $conversation = slaConversation($queue, ['first_response_due_at' => now()->addMinutes(60)]);

    app(App\Domain\Support\Actions\AddInternalNote::class)->execute(
        $conversation,
        'Nota interna, el cliente no ve esto.',
        $staff,
    );

    $fresh = $conversation->fresh();

    // This is the property the whole lifecycle turns on: a note is not a reply,
    // so it must not stop the clock.
    expect($fresh->first_responded_at)->toBeNull()
        ->and($fresh->first_response_due_at)->not->toBeNull();
})->group('R2-15');

test('resolving stops the clocks', function (): void {
    $queue = slaQueue();
    $staff = staffMember($queue);

    $conversation = slaConversation($queue, [
        'resolution_due_at' => now()->addDay(),
        'next_staff_response_due_at' => now()->addHours(2),
        'status' => 'waiting_staff',
    ]);

    app(App\Domain\Support\Actions\ResolveConversation::class)->execute($conversation, $staff);

    $fresh = $conversation->fresh();

    expect($fresh->status)->toBe(SupportConversationStatus::Resolved)
        ->and($fresh->resolution_due_at)->toBeNull()
        ->and($fresh->next_staff_response_due_at)->toBeNull();
})->group('R2-15');

test('closing stops the clocks', function (): void {
    $queue = slaQueue();
    $staff = staffMember($queue);

    $conversation = slaConversation($queue, [
        'resolution_due_at' => now()->addDay(),
        'status' => 'waiting_staff',
    ]);

    // Closing goes through the endpoint on purpose: that is the path a staff
    // member actually takes, and it carries the permission and queue-membership
    // checks that the bare action would skip.
    $staff->givePermissionTo(
        Permission::firstOrCreate(['name' => 'support.resolve', 'guard_name' => 'web'])
    );

    $this->actingAs($staff)
        ->postJson("/api/support/conversations/{$conversation->id}/close")
        ->assertOk();

    $fresh = $conversation->fresh();

    expect($fresh->status)->toBe(SupportConversationStatus::Closed)
        ->and($fresh->resolution_due_at)->toBeNull();
})->group('R2-15');

test('reopening starts the clocks again', function (): void {
    $queue = slaQueue();
    $staff = staffMember($queue);

    $conversation = slaConversation($queue, [
        'status' => 'resolved',
        'first_response_due_at' => null,
        'next_staff_response_due_at' => null,
        'resolution_due_at' => null,
    ]);

    app(App\Domain\Support\Actions\ReopenConversation::class)->execute($conversation, $staff);

    $fresh = $conversation->fresh();

    // The work is live again, so a new clock runs — this is the part that was
    // missing: reopening left every due date null, so a reopened conversation
    // could never breach.
    expect($fresh->status)->toBe(SupportConversationStatus::WaitingStaff)
        ->and($fresh->first_response_due_at)->not->toBeNull()
        ->and($fresh->resolution_due_at)->toBeNull();
})->group('R2-15');

/* -------------------------------------------------------------------------- */
/* R2-16 — the scanner                                                         */
/* -------------------------------------------------------------------------- */

test('a breached first response is recorded once', function (): void {
    $queue = slaQueue();

    $conversation = slaConversation($queue, [
        'first_response_due_at' => now()->subMinute(),
        'status' => 'waiting_staff',
    ]);

    (new ScanSupportSla)->handle();

    expect(SupportSlaEvent::query()
        ->where('conversation_id', $conversation->id)
        ->where('metric', 'first_response')
        ->where('level', 'breach')
        ->count())->toBe(1);

    // A second scan must not duplicate it.
    (new ScanSupportSla)->handle();

    expect(SupportSlaEvent::query()
        ->where('conversation_id', $conversation->id)
        ->where('metric', 'first_response')
        ->where('level', 'breach')
        ->count())->toBe(1);
})->group('R2-16');

test('a warning is recorded before the deadline and not after', function (): void {
    $queue = slaQueue();

    // Due in 20 minutes, warning threshold is 30 → inside the window.
    $warned = slaConversation($queue, [
        'first_response_due_at' => now()->addMinutes(20),
        'status' => 'waiting_staff',
    ]);

    // Due in 5 hours → outside the window.
    $untouched = slaConversation($queue, [
        'first_response_due_at' => now()->addHours(5),
        'status' => 'waiting_staff',
    ]);

    (new ScanSupportSla)->handle();

    expect(SupportSlaEvent::query()
        ->where('conversation_id', $warned->id)
        ->where('level', 'warning')
        ->count())->toBe(1);

    expect(SupportSlaEvent::query()
        ->where('conversation_id', $untouched->id)
        ->count())->toBe(0);
})->group('R2-16');

test('a finished conversation is never scanned', function (): void {
    $queue = slaQueue();

    foreach (['resolved', 'closed'] as $status) {
        $conversation = slaConversation($queue, [
            // Already overdue, but the conversation is over.
            'first_response_due_at' => now()->subDay(),
            'resolution_due_at' => now()->subDay(),
            'status' => $status,
        ]);

        (new ScanSupportSla)->handle();

        expect(SupportSlaEvent::query()
            ->where('conversation_id', $conversation->id)
            ->count())->toBe(0, "a {$status} conversation must not be scanned");
    }
})->group('R2-16');

test('a breach carries the due date it was measured against', function (): void {
    $queue = slaQueue();

    $dueAt = now()->subMinutes(5);
    $conversation = slaConversation($queue, [
        'first_response_due_at' => $dueAt,
        'status' => 'waiting_staff',
    ]);

    (new ScanSupportSla)->handle();

    $event = SupportSlaEvent::query()
        ->where('conversation_id', $conversation->id)
        ->where('level', 'breach')
        ->firstOrFail();

    // The escalation reads this to say how late it is, so it must be the
    // deadline rather than the moment the scan happened.
    expect($event->due_at->timestamp)->toBe($dueAt->timestamp)
        ->and($event->metric)->toBe('first_response')
        ->and($event->level)->toBe('breach');
})->group('R2-16');

test('a breach emits an event for the escalation listener', function (): void {
    Illuminate\Support\Facades\Event::fake([SupportSlaEventEmitted::class]);

    $queue = slaQueue();
    $conversation = slaConversation($queue, [
        'first_response_due_at' => now()->subMinute(),
        'status' => 'waiting_staff',
    ]);

    (new ScanSupportSla)->handle();

    Illuminate\Support\Facades\Event::assertDispatched(
        SupportSlaEventEmitted::class,
        fn (SupportSlaEventEmitted $event): bool => $event->slaEvent->conversation_id === $conversation->id,
    );
})->group('R2-16');