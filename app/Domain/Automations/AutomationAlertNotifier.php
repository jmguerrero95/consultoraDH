<?php

declare(strict_types=1);

namespace App\Domain\Automations;

use App\Models\AutomationRun;
use App\Services\Telegram\TelegramNotifier;

/**
 * Announces a failed or blocked automation run to the subscribed destinations.
 *
 * The alert is about an internal rule and its outcome. It carries the rule's
 * name, the run id and the state — not the payload the rule was processing, and
 * not the client record it touched.
 */
final class AutomationAlertNotifier
{
    public function __construct(private readonly TelegramNotifier $telegram) {}

    public function runFinished(AutomationRun $run, string $status): void
    {
        if (! in_array($status, ['partial', 'blocked'], true)) {
            return;
        }

        $ruleName = $run->rule?->name ?? 'Regla sin nombre';

        $this->telegram->notifyEvent(
            'automation_alert',
            "⚠️ Alerta de automatización\n\nLa regla «{$ruleName}» terminó como {$status}.\nEjecución #{$run->id}.",
            null,
            'automation-run:'.$run->id.':'.$status,
        );
    }
}
