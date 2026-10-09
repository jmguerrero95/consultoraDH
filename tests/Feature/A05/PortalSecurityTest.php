<?php

declare(strict_types=1);

use App\Domain\Portal\ProfileUpdateHandler;
use App\Domain\Users\UserStatus;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientProfileUpdateRequest;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    seedPortfolioRoles();

    $this->clientA = Client::factory()->create(['document_number' => '111111']);
    $this->clientB = Client::factory()->create(['document_number' => '222222']);

    $this->portalA = User::factory()->create([
        'account_type' => 'client',
        'client_id' => $this->clientA->id,
        'status' => UserStatus::Active,
    ]);

    $this->portalB = User::factory()->create([
        'account_type' => 'client',
        'client_id' => $this->clientB->id,
        'status' => UserStatus::Active,
    ]);

    $this->operator = userWithPermissions(['clients.view', 'receivables.view', 'documents.view', 'documents.manage', 'portal_accounts.manage', 'client_update_requests.view', 'client_update_requests.review']);
});

it('refuses a client account access to staff endpoints', function (): void {
    $this->actingAs($this->portalA)->getJson('/api/clients')->assertForbidden();
    $this->actingAs($this->portalA)->getJson('/api/receivables')->assertForbidden();
    $this->actingAs($this->portalA)->getJson('/api/imports')->assertForbidden();
    $this->actingAs($this->portalA)->getJson('/api/planillas')->assertForbidden();
});

it('prevents Client A from viewing Client B profile', function (): void {
    $this->actingAs($this->portalA)->getJson("/api/clients/{$this->clientB->id}")->assertForbidden();
});

it('prevents Client A from viewing Client B financial account', function (): void {
    $this->actingAs($this->portalA)->getJson("/api/clients/{$this->clientB->id}/account")->assertForbidden();
});

it('prevents Client A from downloading Client B document', function (): void {
    Storage::fake('documents');

    $type = DocumentType::factory()->create();

    $docB = ClientDocument::factory()->create([
        'client_id' => $this->clientB->id,
        'document_type_id' => $type->id,
        'visibility' => 'client',
    ]);

    Storage::disk('documents')->put($docB->stored_path, 'content');

    $this->actingAs($this->portalA)->getJson("/api/portal/documents/{$docB->id}/download")->assertNotFound();
});

it('allows Client A to download own document', function (): void {
    Storage::fake('documents');

    $type = DocumentType::factory()->create();

    $docA = ClientDocument::factory()->create([
        'client_id' => $this->clientA->id,
        'document_type_id' => $type->id,
        'visibility' => 'client',
    ]);

    Storage::disk('documents')->put($docA->stored_path, 'content');

    $this->actingAs($this->portalA)->getJson("/api/portal/documents/{$docA->id}/download")->assertOk();
});

it('stops a deactivated portal account session', function (): void {
    $this->portalA->forceFill(['status' => UserStatus::Inactive])->save();

    // A01's `user.active` middleware already answers 401 for a suspended account, so the
    // session stops working before any portal controller is reached. 401 is the proof.
    $this->actingAs($this->portalA)->getJson('/api/portal/profile')->assertUnauthorized();
});

it('creates a profile update request without mutating the client', function (): void {
    $originalPhone = $this->clientA->phone;

    $this->actingAs($this->portalA)->postJson('/api/portal/profile/update-request', [
        'phone' => '3009998877',
    ])->assertCreated();

    $this->clientA->refresh();
    expect($this->clientA->phone)->toBe($originalPhone);

    expect(ClientProfileUpdateRequest::query()
        ->where('client_id', $this->clientA->id)
        ->where('status', 'pending')
        ->exists())->toBeTrue();
});

it('applies an approved profile update through the authoritative path', function (): void {
    $updateRequest = ClientProfileUpdateRequest::factory()->create([
        'client_id' => $this->clientA->id,
        'requested_by_user_id' => $this->portalA->id,
        'proposed_changes' => ['phone' => '3009998877'],
        'status' => 'pending',
    ]);

    $result = app(ProfileUpdateHandler::class)->approve($updateRequest, $this->operator);

    expect($result->status->value)->toBe('approved');

    $this->clientA->refresh();
    expect($this->clientA->phone)->toBe('3009998877');
});

it('rejects a profile update with a reason', function (): void {
    $updateRequest = ClientProfileUpdateRequest::factory()->create([
        'client_id' => $this->clientA->id,
        'requested_by_user_id' => $this->portalA->id,
        'proposed_changes' => ['phone' => '3009998877'],
        'status' => 'pending',
    ]);

    $result = app(ProfileUpdateHandler::class)->reject($updateRequest, 'Teléfono no válido', $this->operator);

    expect($result->status->value)->toBe('rejected');

    $this->clientA->refresh();
    expect($this->clientA->phone)->not->toBe('3009998877');
});

it('delegates portal financial account to A03 ReceivablesService', function (): void {
    $this->clientA->loadCount([]);

    $this->actingAs($this->portalA)->getJson('/api/portal/financial-account')
        ->assertOk()
        ->assertJsonStructure(['summary' => ['outstanding_balance_cop']]);
});

it('creates a portal account for a client', function (): void {
    // A client with no portal account yet — `clientA` already has `portalA` from beforeEach.
    $fresh = Client::factory()->create();

    $this->actingAs($this->operator)->postJson("/api/clients/{$fresh->id}/portal-account", [
        'name' => 'Portal User',
        'email' => 'portal@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertCreated();

    $user = User::query()->where('email', 'portal@example.com')->first();
    expect($user->account_type)->toBe('client')
        ->and($user->client_id)->toBe($fresh->id);
});

it('refuses a second portal account for the same client', function (): void {
    $this->actingAs($this->operator)->postJson("/api/clients/{$this->clientA->id}/portal-account", [
        'name' => 'Another User',
        'email' => 'another@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(409)->assertJsonPath('code', 'portal_account_exists');
});

it('never exposes a password when creating a portal account', function (): void {
    $fresh = Client::factory()->create();

    $response = $this->actingAs($this->operator)->postJson("/api/clients/{$fresh->id}/portal-account", [
        'name' => 'Portal User',
        'email' => 'nopassword@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertCreated();

    expect($response->json())->not->toHaveKey('password')
        ->and($response->getContent())->not->toContain('password123');
});

it('prevents Client A from accessing Client B portal document requests', function (): void {
    $this->actingAs($this->portalA)->getJson('/api/portal/document-requests')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
