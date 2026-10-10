<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportQueue;
use App\Models\SupportQueueMember;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses()->group('A06');

test('debug route binding with manual fetch', function (): void {
    $client = Client::factory()->create();
    $clientUser = User::factory()->create([
        'name' => 'Client User',
        'email' => 'client' . time() . '@example.com',
        'password' => bcrypt('password'),
        'status' => 'active',
        'account_type' => 'client',
        'client_id' => $client->id,
    ]);

    $staff = User::factory()->create([
        'account_type' => 'staff',
        'status' => 'active',
    ]);

    $queue = SupportQueue::factory()->create();
    SupportQueueMember::create(['queue_id' => $queue->id, 'user_id' => $staff->id]);

    $conversation = SupportConversation::create([
        'client_id' => $client->id,
        'queue_id' => $queue->id,
        'subject' => 'Test',
        'status' => 'waiting_staff',
        'origin_channel' => 'portal',
    ]);

    $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');

    // Test with manual conversation fetch - bypass model binding
    $response = $this->actingAs($clientUser)
        ->postJson("/api/portal/support/conversations/{$conversation->id}/attachments", [
            'file' => $file,
        ]);

    // Debug output
    dump('Response status: ' . $response->status());
    dump('Response json: ' . json_encode($response->json()));

    $response->assertCreated()
        ->assertJsonStructure(['id', 'original_name', 'mime_type', 'size_bytes', 'sha256']);
});