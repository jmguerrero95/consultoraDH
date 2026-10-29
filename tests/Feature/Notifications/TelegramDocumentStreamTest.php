<?php

declare(strict_types=1);

use App\Domain\Documents\DocumentReviewStatus;
use App\Domain\Documents\DocumentVisibility;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\DocumentType;
use App\Services\Telegram\TelegramDocumentDeliveryService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * PERF-01-R1 — the stream is closed even when the marker callback fails.
 *
 * The document is streamed, not read into memory, so the handle must be
 * released on every exit. The durable marker that runs before `sendDocument`
 * belongs inside that scope: if writing it fails, nothing external has happened
 * yet, and the handle still has to be closed.
 */
beforeEach(function (): void {
    seedPortfolioRoles();

    $this->employee = userWithPermissions(['documents.view']);
    $this->employee->forceFill(['account_type' => 'staff'])->save();
    $this->client = Client::factory()->create();
    $this->type = DocumentType::factory()->create();

    config(['services.telegram.bot_token' => 'test-bot-token']);

    Http::fake();
});

function streamedDocument(Client $client, DocumentType $type): ClientDocument
{
    return ClientDocument::factory()->create([
        'client_id' => $client->id,
        'document_type_id' => $type->id,
        'title' => 'Documento sintético QA',
        'original_name' => 'documento-qa.pdf',
        'stored_path' => 'clients/qa/documento-qa.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 16,
        'visibility' => DocumentVisibility::Internal,
        'review_status' => DocumentReviewStatus::Received,
        'archived_at' => null,
    ]);
}

it('closes the document stream when the pre-send marker fails', function (): void {
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, 'PDF-CONTENT-QA');
    rewind($stream);

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->once()->andReturnTrue();
    $disk->shouldReceive('size')->once()->andReturn(14);
    $disk->shouldReceive('readStream')->once()->andReturn($stream);

    $realDisk = Storage::disk('documents');
    Storage::set('documents', $disk);

    $document = streamedDocument($this->client, $this->type);

    try {
        expect(fn () => app(TelegramDocumentDeliveryService::class)->deliver(
            'qa-chat',
            $this->employee,
            $document,
            function (): void {
                throw new RuntimeException('external marker could not be written');
            },
        ))->toThrow(RuntimeException::class, 'external marker could not be written');

        // The handle did not leak just because the callback threw.
        expect(is_resource($stream))->toBeFalse();

        // And nothing was sent to Telegram: the marker runs before the request.
        Http::assertNothingSent();
    } finally {
        Storage::set('documents', $realDisk);
    }
});
