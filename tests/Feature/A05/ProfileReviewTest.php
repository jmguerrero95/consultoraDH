<?php

declare(strict_types=1);

use App\Domain\Portal\ProfileUpdateRequestStatus;
use App\Domain\Users\UserStatus;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\ClientProfileUpdateRequest;
use App\Models\User;

/**
 * A05-R1 §3 — the staff half of a client's profile proposal.
 *
 * The gap: `client_update_requests.view` and `.review` were seeded and enforced by
 * nothing, so a client could submit a change and no ordinary staff member had a way to
 * act on it. And the approval wrote the client row directly, skipping A02's
 * `UpdateClient` — and with it the `ClientUpdated` event the audit trail is built on.
 */
beforeEach(function (): void {
    seedPortfolioRoles();

    $this->client = Client::factory()->create([
        'phone' => '3001112233',
        'address' => 'Calle 1 # 2-3',
    ]);

    $this->portal = User::factory()->create([
        'account_type' => 'client',
        'client_id' => $this->client->id,
        'status' => UserStatus::Active,
    ]);

    $this->reviewer = userWithPermissions(['client_update_requests.view', 'client_update_requests.review']);
});

/** A pending proposal made through the real portal endpoint. */
function proposalFor($test): ClientProfileUpdateRequest
{
    $test->actingAs($test->portal)
        ->postJson('/api/portal/profile/update-request', ['phone' => '3009991122'])
        ->assertCreated();

    return ClientProfileUpdateRequest::query()->latest('id')->firstOrFail();
}

// ---------------------------------------------------------------------------
// TEST U1 — the real staff flow
// ---------------------------------------------------------------------------

it('U1: staff approve through the API, applying the change through A02', function (): void {
    $request = proposalFor($this);

    // The master is untouched by the proposal itself.
    expect($this->client->refresh()->phone)->toBe('3001112233');

    $listed = $this->actingAs($this->reviewer)
        ->getJson('/api/client-profile-update-requests')
        ->assertOk();

    expect(collect($listed->json('data'))->pluck('id'))->toContain($request->id);
    expect($listed->json('data.0.proposed_changes'))->toBe(['phone' => '3009991122']);

    $approved = $this->actingAs($this->reviewer)
        ->postJson("/api/client-profile-update-requests/{$request->id}/approve", ['note' => 'Verificado'])
        ->assertOk();

    expect($approved->json('status'))->toBe('approved')
        ->and($approved->json('applied_at'))->not->toBeNull();

    // The master changed.
    expect($this->client->refresh()->phone)->toBe('3009991122');

    $row = ClientProfileUpdateRequest::query()->findOrFail($request->id);

    expect($row->status)->toBe(ProfileUpdateRequestStatus::Approved)
        ->and($row->applied_at)->not->toBeNull();

    // §R1: A02's own audit behaviour. `UpdateClient` fires `ClientUpdated`, which the
    // audit layer records as `client.updated`. Before the fix this event never happened,
    // because the handler wrote the row itself.
    expect(AuditEvent::query()
        ->where('action', 'client.updated')
        ->where('subject_type', Client::class)
        ->where('subject_id', $this->client->id)
        ->exists())->toBeTrue();

    // And the decision itself is audited separately.
    expect(AuditEvent::query()
        ->where('action', 'client_profile_update.applied')
        ->where('subject_id', $this->client->id)
        ->exists())->toBeTrue();
});

it('U1: a rejection needs a reason and leaves the client alone', function (): void {
    $request = proposalFor($this);

    $this->actingAs($this->reviewer)
        ->postJson("/api/client-profile-update-requests/{$request->id}/reject", [])
        ->assertStatus(422);

    $this->actingAs($this->reviewer)
        ->postJson("/api/client-profile-update-requests/{$request->id}/reject", [
            'note' => 'El número no corresponde al titular',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'rejected');

    expect($this->client->refresh()->phone)->toBe('3001112233')
        ->and(ClientProfileUpdateRequest::query()->findOrFail($request->id)->applied_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// TEST U2 — an A02 refusal must not approve anything
// ---------------------------------------------------------------------------

it('U2: an update A02 refuses leaves the request pending and the client unchanged', function (): void {
    $request = proposalFor($this);

    // A real refusal from the frozen action, not a fault injected into it. `phone` is
    // `varchar(40)`, and PostgreSQL refuses a longer value — so `UpdateClient::execute()`
    // genuinely throws while saving, which is exactly the situation the approval path has
    // to survive.
    $demasiadoLargo = str_repeat('9', 80);

    $request->forceFill(['proposed_changes' => ['phone' => $demasiadoLargo]])->save();
    $request->refresh();

    $this->actingAs($this->reviewer)
        ->postJson("/api/client-profile-update-requests/{$request->id}/approve")
        ->assertStatus(409);

    $row = ClientProfileUpdateRequest::query()->findOrFail($request->id);

    // Nothing claimed to have happened: the request is still pending, nothing was applied,
    // and the master still holds the old value.
    expect($row->status)->toBe(ProfileUpdateRequestStatus::Pending)
        ->and($row->applied_at)->toBeNull()
        ->and($row->reviewed_at)->toBeNull()
        ->and($this->client->refresh()->phone)->toBe('3001112233');

    // And no approval audit was written for a change that did not happen.
    expect(AuditEvent::query()
        ->where('action', 'client_profile_update.applied')
        ->where('subject_id', $this->client->id)
        ->exists())->toBeFalse();
});

it('U2: a decided request cannot be decided again', function (): void {
    $request = proposalFor($this);

    $this->actingAs($this->reviewer)
        ->postJson("/api/client-profile-update-requests/{$request->id}/reject", ['note' => 'No procede'])
        ->assertOk();

    $this->actingAs($this->reviewer)
        ->postJson("/api/client-profile-update-requests/{$request->id}/approve")
        ->assertStatus(409);

    expect(ClientProfileUpdateRequest::query()->findOrFail($request->id)->status)
        ->toBe(ProfileUpdateRequestStatus::Rejected);
});

// ---------------------------------------------------------------------------
// TEST U3 — the permission boundary
// ---------------------------------------------------------------------------

it('U3: an account without the review permission cannot approve or reject', function (): void {
    $request = proposalFor($this);

    $reader = userWithPermissions(['client_update_requests.view']);

    $this->actingAs($reader)
        ->postJson("/api/client-profile-update-requests/{$request->id}/approve")
        ->assertForbidden();

    $this->actingAs($reader)
        ->postJson("/api/client-profile-update-requests/{$request->id}/reject", ['note' => 'No'])
        ->assertForbidden();

    expect(ClientProfileUpdateRequest::query()->findOrFail($request->id)->status)
        ->toBe(ProfileUpdateRequestStatus::Pending)
        ->and($this->client->refresh()->phone)->toBe('3001112233');
});

it('U3: an account without the view permission cannot list them', function (): void {
    proposalFor($this);

    $this->actingAs(userWithPermissions(['novelties.view']))
        ->getJson('/api/client-profile-update-requests')
        ->assertForbidden();
});
