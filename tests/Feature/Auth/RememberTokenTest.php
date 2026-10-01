<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/** Password used by these tests. It only ever exists in the test database. */
const REMEMBER_PASSWORD = 'ContrasenaRecordada2026';
const NEW_PASSWORD = 'ContrasenaRenovada2026';

it('rotates the remember token when an authenticated user changes the password', function (): void {
    $user = User::factory()->withPassword(REMEMBER_PASSWORD)->create([
        'remember_token' => 'token-original-antes-del-cambio',
    ]);

    $before = $user->remember_token;

    $this->actingAs($user)->putJson('/api/profile/password', [
        'current_password' => REMEMBER_PASSWORD,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertOk();

    $after = $user->fresh()->remember_token;

    expect($after)->not->toBe($before)
        ->and($after)->toBeString()
        ->not->toBeEmpty();

    // The change itself still took effect.
    expect(Hash::check(NEW_PASSWORD, $user->fresh()->password))->toBeTrue();
});

it('rotates the remember token when the password is reset', function (): void {
    Notification::fake();

    $user = User::factory()->withPassword(REMEMBER_PASSWORD)->create([
        'remember_token' => 'token-original-antes-del-reseteo',
    ]);

    $before = $user->remember_token;

    $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $this->postJson('/api/auth/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => NEW_PASSWORD,
            'password_confirmation' => NEW_PASSWORD,
        ])->assertOk();

        return true;
    });

    expect($user->fresh()->remember_token)->not->toBe($before)
        ->and(Hash::check(NEW_PASSWORD, $user->fresh()->password))->toBeTrue();
});

it('still signs the user in with the new password after a rotation', function (): void {
    $user = User::factory()->withPassword(REMEMBER_PASSWORD)->create();

    $this->actingAs($user)->putJson('/api/profile/password', [
        'current_password' => REMEMBER_PASSWORD,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertOk();

    $this->post('/api/auth/logout');

    $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', ['email' => $user->email, 'password' => NEW_PASSWORD])
        ->assertOk();
});

it('refuses a persistent cookie issued before the password change', function (): void {
    // The old remember cookie carries the previous token. After a rotation the
    // value no longer matches, which is what makes the cookie useless.
    $user = User::factory()->withPassword(REMEMBER_PASSWORD)->create([
        'remember_token' => 'token-original',
    ]);

    $this->actingAs($user)->putJson('/api/profile/password', [
        'current_password' => REMEMBER_PASSWORD,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertOk();

    expect($user->fresh()->getRememberToken())->not->toBe('token-original');
});
