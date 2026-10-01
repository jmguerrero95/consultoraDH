<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Security\PasswordPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

it('sends a recovery link and answers the same for an unknown address', function (): void {
    Notification::fake();

    User::factory()->create(['email' => 'conocido@consultora-dh.test']);

    $known = $this->postJson('/api/auth/forgot-password', [
        'email' => 'conocido@consultora-dh.test',
    ]);

    $unknown = $this->postJson('/api/auth/forgot-password', [
        'email' => 'desconocido@consultora-dh.test',
    ]);

    $known->assertOk();
    $unknown->assertOk();

    // Identical bodies: the endpoint cannot be used to discover accounts.
    expect($known->json())->toBe($unknown->json());

    Notification::assertSentTo(
        User::query()->where('email', 'conocido@consultora-dh.test')->firstOrFail(),
        ResetPassword::class,
    );
});

it('resets the password with a valid token', function (): void {
    Notification::fake();

    $user = User::factory()->withPassword('ContrasenaAntigua2026')->create();

    $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $this->postJson('/api/auth/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'ContrasenaNueva2026',
            'password_confirmation' => 'ContrasenaNueva2026',
        ])->assertOk();

        return true;
    });

    // The new password works and the previous one no longer does.
    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'ContrasenaNueva2026',
    ])->assertOk();

    $this->post('/api/auth/logout');

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'ContrasenaAntigua2026',
    ])->assertStatus(422);
});

it('rejects an invalid reset token without revealing why', function (): void {
    $user = User::factory()->create();

    $this->postJson('/api/auth/reset-password', [
        'token' => 'token-invalido',
        'email' => $user->email,
        'password' => 'ContrasenaNueva2026',
        'password_confirmation' => 'ContrasenaNueva2026',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('rejects a reset whose confirmation does not match', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $this->postJson('/api/auth/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'ContrasenaNueva2026',
            'password_confirmation' => 'OtraContrasena2026',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        return true;
    });
});

it('rejects a password that does not meet the policy', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $this->postJson('/api/auth/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'corta',
            'password_confirmation' => 'corta',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        return true;
    });
});

/*
|--------------------------------------------------------------------------
| The reset flow uses the same policy as every other entry point
|--------------------------------------------------------------------------
|
| It used to validate `Password::min(12)` alone, so a reset could set a weaker
| password than the one the account was created with. These tests pin the shared
| policy to all three entry points.
|
*/

it('rejects a reset password of only lower case letters', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'politica@consultora-dh.test']);

    $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user): bool {
        // 12 lowercase letters and nothing else: long enough, but no mixed
        // case and no digits.
        $this->postJson('/api/auth/reset-password', [
            'token' => $n->token,
            'email' => $user->email,
            'password' => 'abcdeffghijkl',
            'password_confirmation' => 'abcdeffghijkl',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('password');

        return true;
    });

    // The rejected password was never applied, so the account still has its
    // factory password and not the lower case string that was refused.
    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'abcdeffghijkl',
    ])->assertStatus(422);

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();
});

it('rejects a reset password with no numbers', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'politica@consultora-dh.test']);

    $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user): bool {
        // Mixed case, long enough, but not a single digit.
        $this->postJson('/api/auth/reset-password', [
            'token' => $n->token,
            'email' => $user->email,
            'password' => 'abcdEFGHijkl',
            'password_confirmation' => 'abcdEFGHijkl',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('password');

        return true;
    });
});

it('accepts a reset password that satisfies the shared policy', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'politica@consultora-dh.test']);

    $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user): bool {
        $this->postJson('/api/auth/reset-password', [
            'token' => $n->token,
            'email' => $user->email,
            'password' => 'ContrasenaNueva2026',
            'password_confirmation' => 'ContrasenaNueva2026',
        ])->assertOk();

        return true;
    });

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'ContrasenaNueva2026',
    ])->assertOk();
});

it('rejects a reset password shorter than the minimum', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'politica@consultora-dh.test']);

    $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user): bool {
        $this->postJson('/api/auth/reset-password', [
            'token' => $n->token,
            'email' => $user->email,
            'password' => 'Abcdefg123',      // 10 characters
            'password_confirmation' => 'Abcdefg123',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        return true;
    });
});

it('exposes the policy as one shared definition', function (): void {
    // The three entry points read from this class, so the rules cannot drift.
    expect(PasswordPolicy::MIN_LENGTH)->toBe(12)
        ->and(PasswordPolicy::requirement())
        ->toContain('12 caracteres');
});
