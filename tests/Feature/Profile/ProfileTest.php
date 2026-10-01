<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/** Password used by these tests. It exists only inside the test database. */
const CURRENT_PASSWORD = 'ContrasenaActual2026';

function userWithPassword(array $attributes = []): User
{
    return User::factory()->withPassword(CURRENT_PASSWORD)->create($attributes);
}

it('shows the profile of the signed in user', function (): void {
    $user = userWithPassword();

    $this->actingAs($user)->getJson('/api/profile')
        ->assertOk()
        ->assertJsonPath('user.email', $user->email)
        ->assertJsonMissingPath('user.password')
        ->assertJsonMissingPath('user.remember_token');
});

it('updates the display name', function (): void {
    $user = userWithPassword();

    $this->actingAs($user)->patchJson('/api/profile', ['name' => 'Nombre Nuevo'])->assertOk();

    expect($user->fresh()->name)->toBe('Nombre Nuevo');
});

it('rejects a name that is too short', function (): void {
    $user = userWithPassword();

    $this->actingAs($user)
        ->patchJson('/api/profile', ['name' => 'AB'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    expect($user->fresh()->name)->toBe($user->name);
});

it('rejects a missing name', function (): void {
    $user = userWithPassword();

    $this->actingAs($user)
        ->patchJson('/api/profile', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('changes the email address when the current password is correct', function (): void {
    $user = userWithPassword(['email' => 'antes@consultora-dh.test']);

    $this->actingAs($user)->putJson('/api/profile/email', [
        'email' => 'DESPUES@Consultora-DH.test',
        'current_password' => CURRENT_PASSWORD,
    ])->assertOk()->assertJsonPath('user.email', 'despues@consultora-dh.test');

    expect($user->fresh()->email)->toBe('despues@consultora-dh.test');
});

it('requires the current password to change the email address', function (): void {
    $user = userWithPassword(['email' => 'antes@consultora-dh.test']);

    $this->actingAs($user)
        ->putJson('/api/profile/email', [
            'email' => 'despues@consultora-dh.test',
            'current_password' => 'ContrasenaIncorrecta2026',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('current_password');

    expect($user->fresh()->email)->toBe('antes@consultora-dh.test');
});

it('requires the current password to be present at all', function (): void {
    $user = userWithPassword();

    $this->actingAs($user)
        ->putJson('/api/profile/email', ['email' => 'despues@consultora-dh.test'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('current_password');
});

it('rejects an invalid email address', function (): void {
    $user = userWithPassword();

    $this->actingAs($user)
        ->putJson('/api/profile/email', [
            'email' => 'esto-no-es-un-correo',
            'current_password' => CURRENT_PASSWORD,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('rejects an email address already in use', function (): void {
    User::factory()->create(['email' => 'ocupado@consultora-dh.test']);
    $user = userWithPassword();

    $this->actingAs($user)
        ->putJson('/api/profile/email', [
            'email' => 'ocupado@consultora-dh.test',
            'current_password' => CURRENT_PASSWORD,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('changes the password when the current password is correct', function (): void {
    $user = userWithPassword();

    $this->actingAs($user)->putJson('/api/profile/password', [
        'current_password' => CURRENT_PASSWORD,
        'password' => 'ContrasenaNueva2026',
        'password_confirmation' => 'ContrasenaNueva2026',
    ])->assertOk();

    expect(Hash::check('ContrasenaNueva2026', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->password_changed_at)->not->toBeNull();
});

it('rejects a password change with the wrong current password', function (): void {
    $user = userWithPassword();

    $this->actingAs($user)
        ->putJson('/api/profile/password', [
            'current_password' => 'ContrasenaIncorrecta2026',
            'password' => 'ContrasenaNueva2026',
            'password_confirmation' => 'ContrasenaNueva2026',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('current_password');

    expect(Hash::check(CURRENT_PASSWORD, $user->fresh()->password))->toBeTrue();
});

it('requires the password confirmation to match', function (): void {
    $user = userWithPassword();

    $this->actingAs($user)
        ->putJson('/api/profile/password', [
            'current_password' => CURRENT_PASSWORD,
            'password' => 'ContrasenaNueva2026',
            'password_confirmation' => 'OtraContrasena2026',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

it('rejects a password that does not meet the policy', function (): void {
    $user = userWithPassword();

    $this->actingAs($user)
        ->putJson('/api/profile/password', [
            'current_password' => CURRENT_PASSWORD,
            'password' => 'corta',
            'password_confirmation' => 'corta',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

it('refuses to reuse the current password as the new one', function (): void {
    $user = userWithPassword();

    $this->actingAs($user)
        ->putJson('/api/profile/password', [
            'current_password' => CURRENT_PASSWORD,
            'password' => CURRENT_PASSWORD,
            'password_confirmation' => CURRENT_PASSWORD,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

it('refuses every profile operation to a guest', function (): void {
    $this->patchJson('/api/profile', ['name' => 'Intruso'])->assertStatus(401);
    $this->putJson('/api/profile/email', ['email' => 'a@b.test'])->assertStatus(401);
    $this->putJson('/api/profile/password', ['password' => 'ContrasenaNueva2026'])->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| Verification state on an address change
|--------------------------------------------------------------------------
|
| Verification is not enforced in A01, so this changes nothing visible today.
| The point is that the column must not claim proof of an address the account
| never proved, in case verification is switched on later.
|
*/

it('clears the verification timestamp when the address actually changes', function (): void {
    $user = userWithPassword([
        'email' => 'verificada@consultora-dh.test',
        'email_verified_at' => now()->subDays(30),
    ]);

    expect($user->email_verified_at)->not->toBeNull();

    $this->actingAs($user)->putJson('/api/profile/email', [
        'email' => 'nueva@consultora-dh.test',
        'current_password' => CURRENT_PASSWORD,
    ])->assertOk();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('keeps the verification timestamp when the address is unchanged', function (): void {
    $verifiedAt = now()->subDays(30);

    $user = userWithPassword([
        'email' => 'verificada@consultora-dh.test',
        'email_verified_at' => $verifiedAt,
    ]);

    // The same address again, differing only in case and surrounding space.
    // The model lower cases and trims on assignment, so this is not a change of
    // address and the proof of the existing one still stands.
    $this->actingAs($user)->putJson('/api/profile/email', [
        'email' => '  VERIFICADA@Consultora-DH.TEST  ',
        'current_password' => CURRENT_PASSWORD,
    ])->assertOk()->assertJsonPath('user.email', 'verificada@consultora-dh.test');

    expect($user->fresh()->email_verified_at)->not->toBeNull()
        ->and($user->fresh()->email)->toBe('verificada@consultora-dh.test');
});

it('leaves an already unverified account unverified', function (): void {
    $user = userWithPassword([
        'email' => 'sin-verificar@consultora-dh.test',
        'email_verified_at' => null,
    ]);

    $this->actingAs($user)->putJson('/api/profile/email', [
        'email' => 'otra@consultora-dh.test',
        'current_password' => CURRENT_PASSWORD,
    ])->assertOk();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('does not clear the verification timestamp when the change is rejected', function (): void {
    $user = userWithPassword([
        'email' => 'verificada@consultora-dh.test',
        'email_verified_at' => now()->subDays(30),
    ]);

    $this->actingAs($user)->putJson('/api/profile/email', [
        'email' => 'nueva@consultora-dh.test',
        'current_password' => 'ContrasenaIncorrecta2026',
    ])->assertStatus(422);

    expect($user->fresh()->email_verified_at)->not->toBeNull()
        ->and($user->fresh()->email)->toBe('verificada@consultora-dh.test');
});

it('does not touch the verification timestamp on a profile name update', function (): void {
    $user = userWithPassword([
        'email' => 'verificada@consultora-dh.test',
        'email_verified_at' => now()->subDays(30),
    ]);

    $this->actingAs($user)->patchJson('/api/profile', ['name' => 'Nombre Nuevo'])->assertOk();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});
