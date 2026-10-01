<?php

declare(strict_types=1);

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\MetadataScrubber;
use App\Models\AuditEvent;
use App\Models\User;

const AUDIT_PASSWORD = 'ContrasenaAudit2026';

function auditUser(array $attributes = []): User
{
    return User::factory()->withPassword(AUDIT_PASSWORD)->create($attributes);
}

function lastAuditEvent(): AuditEvent
{
    return AuditEvent::query()->latest('id')->firstOrFail();
}

it('records a successful sign in', function (): void {
    $user = auditUser();

    $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => AUDIT_PASSWORD,
        ])
        ->assertOk();

    $event = lastAuditEvent();

    expect($event->action)->toBe(AuditAction::LoginSucceeded->value)
        ->and($event->user_id)->toBe($user->id)
        ->and($event->created_at)->not->toBeNull();
});

it('records a failed sign in', function (): void {
    $user = auditUser();

    $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'ContrasenaIncorrecta2026',
        ])
        ->assertStatus(422);

    $event = lastAuditEvent();

    expect($event->action)->toBe(AuditAction::LoginFailed->value)
        // Attributed to the account, so an administrator can tell a typo from a
        // probe across many addresses.
        ->and($event->user_id)->toBe($user->id)
        ->and($event->metadata['email'])->toBe($user->email)
        ->and($event->metadata['account_exists'])->toBeTrue();
});

it('records a sign in against an unknown address without an account', function (): void {
    $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'nadie@consultora-dh.test',
            'password' => 'ContrasenaIncorrecta2026',
        ])
        ->assertStatus(422);

    $event = lastAuditEvent();

    expect($event->action)->toBe(AuditAction::LoginFailed->value)
        ->and($event->user_id)->toBeNull()
        ->and($event->metadata['account_exists'])->toBeFalse();
});

it('records the rejection of an inactive account separately', function (): void {
    $user = User::factory()->inactive()->withPassword(AUDIT_PASSWORD)->create();

    $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => AUDIT_PASSWORD,
        ])
        ->assertStatus(422);

    $event = lastAuditEvent();

    expect($event->action)->toBe(AuditAction::LoginRejectedInactive->value)
        ->and($event->user_id)->toBe($user->id)
        ->and($event->metadata['status'])->toBe('inactive');
});

it('records a sign out', function (): void {
    $user = auditUser();

    $this->actingAs($user)->postJson('/api/auth/logout')->assertOk();

    $event = lastAuditEvent();

    expect($event->action)->toBe(AuditAction::Logout->value)
        ->and($event->user_id)->toBe($user->id);
});

it('records a profile update by naming the changed field, not its value', function (): void {
    $user = auditUser(['name' => 'Nombre Anterior']);

    $this->actingAs($user)->patchJson('/api/profile', ['name' => 'Nombre Nuevo'])->assertOk();

    $event = lastAuditEvent();

    expect($event->action)->toBe(AuditAction::ProfileUpdated->value)
        ->and($event->metadata['changed'])->toBe(['name'])
        // The value itself is not duplicated into the audit trail.
        ->and(json_encode($event->metadata))->not->toContain('Nombre Nuevo');
});

it('records an email change with both addresses', function (): void {
    $user = auditUser(['email' => 'antes@consultora-dh.test']);

    $this->actingAs($user)->putJson('/api/profile/email', [
        'email' => 'despues@consultora-dh.test',
        'current_password' => AUDIT_PASSWORD,
    ])->assertOk();

    $event = lastAuditEvent();

    expect($event->action)->toBe(AuditAction::EmailAddressChanged->value)
        ->and($event->metadata['previous_email'])->toBe('antes@consultora-dh.test')
        ->and($event->metadata['new_email'])->toBe('despues@consultora-dh.test');
});

it('records a password change without anything derived from the password', function (): void {
    $user = auditUser();

    $this->actingAs($user)->putJson('/api/profile/password', [
        'current_password' => AUDIT_PASSWORD,
        'password' => 'ContrasenaNueva2026',
        'password_confirmation' => 'ContrasenaNueva2026',
    ])->assertOk();

    $event = lastAuditEvent();

    expect($event->action)->toBe(AuditAction::PasswordChanged->value)
        ->and($event->metadata['self_service'])->toBeTrue();
});

it('never stores a password, a hash or a token in the audit trail', function (): void {
    $user = auditUser();

    $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', ['email' => $user->email, 'password' => AUDIT_PASSWORD])
        ->assertOk();

    $this->patchJson('/api/profile', ['name' => 'Nombre Nuevo'])->assertOk();
    $this->putJson('/api/profile/password', [
        'current_password' => AUDIT_PASSWORD,
        'password' => 'ContrasenaNueva2026',
        'password_confirmation' => 'ContrasenaNueva2026',
    ])->assertOk();
    $this->postJson('/api/auth/logout')->assertOk();

    $rows = AuditEvent::query()->get();

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        $serialised = json_encode([
            'metadata' => $row->metadata,
            'user_agent' => $row->user_agent,
        ]);

        // No plaintext password, no hash, no CSRF token, no session id.
        expect($serialised)
            ->not->toContain(AUDIT_PASSWORD)
            ->not->toContain('ContrasenaNueva2026')
            ->not->toContain('$2y$')
            ->not->toContain('test-csrf-token');
    }
});

it('redacts a sensitive key even if an event tries to record one', function (): void {
    $user = auditUser();

    // A domain event that (incorrectly) passes a password through. The scrubber
    // is the last line of defence.
    $app = app();
    $recorder = $app->make(AuditRecorder::class);
    $recorder->record(AuditAction::ProfileUpdated, $user, [
        'changed' => ['name'],
        'current_password' => 'No deberia aparecer',
    ]);

    $event = lastAuditEvent();

    expect($event->metadata['current_password'])
        ->toBe(MetadataScrubber::REDACTED);
});

it('treats an audit entry as immutable', function (): void {
    $user = auditUser();

    $this->actingAs($user)->postJson('/api/auth/logout')->assertOk();

    $event = lastAuditEvent();

    expect(AuditEvent::query()->whereKey($event->getKey())->exists())->toBeTrue()
        // The model refuses updates and deletes at the ORM level.
        ->and($event->update(['action' => 'manipulada']))->toBeFalse()
        ->and($event->delete())->toBeFalse();
});

it('keeps the audit table free of timestamps other than creation', function (): void {
    $user = auditUser();

    $this->actingAs($user)->postJson('/api/auth/logout')->assertOk();

    expect(lastAuditEvent()->getAttribute('updated_at'))->toBeNull();
});
