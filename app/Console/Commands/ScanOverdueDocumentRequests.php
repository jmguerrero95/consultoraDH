<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Documents\DocumentRequestStatus;
use App\Models\ClientDocumentRequest;
use App\Services\Telegram\TelegramNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * One daily operational alert about document requests past their due date.
 *
 * The message is a count, not a list: a Telegram channel is not the place for
 * a client's name, document type and identifier. The dedupe key is the day, so
 * running the command twice — a retried cron, a manual invocation — produces
 * one message.
 */
final class ScanOverdueDocumentRequests extends Command
{
    protected $signature = 'documents:scan-overdue';

    protected $description = 'Notifica a los destinos suscritos cuántas solicitudes de documentos están vencidas.';

    public function handle(TelegramNotifier $telegram): int
    {
        $today = Carbon::today();

        $overdue = ClientDocumentRequest::query()
            ->whereNotNull('due_on')
            ->where('due_on', '<', $today)
            ->whereNotIn('status', [
                DocumentRequestStatus::Approved->value,
                DocumentRequestStatus::Cancelled->value,
            ])
            ->count();

        if ($overdue === 0) {
            $this->info('No hay solicitudes de documentos vencidas.');

            return self::SUCCESS;
        }

        $queued = $telegram->notifyEvent(
            'document_overdue',
            "📄 Documentos vencidos\n\nHay {$overdue} solicitud(es) de documentos pendientes después de su fecha límite.",
            null,
            'overdue-documents:'.$today->toDateString(),
        );

        $this->info("Solicitudes vencidas: {$overdue}. Destinos encolados: {$queued}.");

        return self::SUCCESS;
    }
}
