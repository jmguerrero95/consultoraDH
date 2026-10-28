<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Models\ClientDocument;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Delivers one authorized private document to a bound chat.
 *
 * ## The file, not a link
 *
 * The bot uploads the bytes with Telegram's `sendDocument`. It never returns a
 * URL, a storage path or a signed link: a permanent URL is a capability that
 * outlives the chat, and `stored_path` is internal metadata. The filename the
 * person sees is the original name, and the bytes are read from the private
 * `documents` disk on the server.
 *
 * ## Authorization is checked on the concrete file
 *
 * A generic "you may read documents" is not enough here. Before sending, this
 * service re-checks the employee's permission, that the document is not
 * archived, that the private file exists and that it fits Telegram's upload
 * limit. The caller's earlier query is not the authorization.
 */
final class TelegramDocumentDeliveryService
{
    /** Telegram's documented bot upload limit. */
    private const MAX_BYTES = 50 * 1024 * 1024;

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  callable|null  $beforeExternalEffect  invoked once, after every
     *                                               local check and before the
     *                                               sendDocument request
     */
    public function deliver(
        string $chatId,
        User $user,
        ClientDocument $document,
        ?callable $beforeExternalEffect = null,
    ): TelegramQueryResult {
        if ($user->cannot('documents.view')) {
            return new TelegramQueryResult('⛔ Su cuenta no tiene permiso para entregar documentos.');
        }

        if ($user->account_type !== 'staff') {
            return new TelegramQueryResult('⛔ Solo una cuenta de personal puede recibir documentos privados.');
        }

        if ($document->archived_at !== null) {
            return new TelegramQueryResult('⛔ Ese documento está archivado y no se puede entregar.');
        }

        $token = config('services.telegram.bot_token');

        if (! is_string($token) || $token === '') {
            return new TelegramQueryResult('⚠️ No hay un token de Telegram configurado para entregar el documento.');
        }

        $disk = Storage::disk('documents');

        if (! is_string($document->stored_path)
            || $document->stored_path === ''
            || ! $disk->exists($document->stored_path)) {
            return new TelegramQueryResult('⚠️ El archivo del documento no está disponible.');
        }

        try {
            $size = (int) $disk->size($document->stored_path);

            if ($size <= 0) {
                return new TelegramQueryResult('⚠️ El archivo del documento está vacío.');
            }

            if ($size > self::MAX_BYTES) {
                return new TelegramQueryResult('⚠️ El documento supera el límite de 50 MB de Telegram.');
            }

            $bytes = $disk->get($document->stored_path);
        } catch (\Throwable $e) {
            Log::error('Telegram document could not be read', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return new TelegramQueryResult('⚠️ No se pudo leer el archivo del documento.');
        }

        $filename = $this->filename($document);

        // Durable marker before the call: the caller writes it to PostgreSQL in
        // its own statement, so a crash during upload cannot be replayed.
        if ($beforeExternalEffect !== null) {
            $beforeExternalEffect();
        }

        try {
            $response = Http::timeout(60)
                ->attach('document', $bytes, $filename, [
                    'Content-Type' => $document->mime_type ?: 'application/octet-stream',
                ])
                ->post("https://api.telegram.org/bot{$token}/sendDocument", [
                    'chat_id' => $chatId,
                    'caption' => mb_substr($document->title, 0, 200),
                ]);
        } catch (\Throwable $e) {
            Log::error('Telegram sendDocument failed', [
                'document_id' => $document->id,
                'error_class' => $e::class,
            ]);

            throw new TelegramExternalEffectFailed('Telegram sendDocument transport failed', 0, $e);
        }

        if (! $response->successful()) {
            Log::warning('Telegram sendDocument rejected', [
                'document_id' => $document->id,
                'status' => $response->status(),
            ]);

            throw new TelegramExternalEffectFailed('Telegram sendDocument was rejected.');
        }

        $this->audit->record(AuditAction::DocumentDelivered, $user, [
            'document_id' => (int) $document->id,
            'client_id' => (int) $document->client_id,
            'chat_id' => $chatId,
            'channel' => 'telegram',
        ], null, $document);

        return new TelegramQueryResult(
            "📎 Documento entregado: {$document->title}\n"
            .'Archivo: '.$filename,
        );
    }

    private function filename(ClientDocument $document): string
    {
        $name = basename((string) $document->original_name);
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', $name) ?? $name;
        $name = trim($name);

        return $name === '' ? 'documento-'.$document->id : mb_substr($name, 0, 180);
    }
}
