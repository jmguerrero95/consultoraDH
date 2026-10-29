<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PERF-01 B03: the bell's first request is a count, not the inbox.
 *
 * The endpoint must answer the same number the list would, scoped to the
 * authenticated account, and must not require any of the staff permissions: a
 * notification is addressed to one user and ownership is the authorisation.
 */
function insertNotification(User $user, ?string $readAt = null): void
{
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\TaskReminderNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => json_encode(['title' => 'Recordatorio', 'message' => 'Tarea pendiente']),
        'read_at' => $readAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('answers only the unread count for the authenticated account', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    insertNotification($user);
    insertNotification($user);
    insertNotification($user, now()->toDateTimeString());
    insertNotification($other);

    $this->actingAs($user)
        ->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertExactJson(['unread' => 2]);
});

it('refuses the count to a guest', function (): void {
    $this->getJson('/api/notifications/unread-count')->assertUnauthorized();
});
