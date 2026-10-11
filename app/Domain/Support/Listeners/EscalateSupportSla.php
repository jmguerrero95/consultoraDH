<?php

declare(strict_types=1);

namespace App\Domain\Support\Listeners;

use App\Domain\Notifications\DatabaseNotificationService;
use App\Domain\Support\Events\SupportSlaEventEmitted;
use App\Jobs\SendTelegramMessage;
use App\Models\SupportConversation;
use App\Models\SupportSlaEvent;
use App\Models\TelegramEndpoint;
use App\Models\User;

class EscalateSupportSla
{
    public function handle(SupportSlaEventEmitted $event): void
    {
        $slaEvent = $event->slaEvent;
        $conversation = $slaEvent->conversation;

        if (! $conversation) {
            return;
        }

        // Escalate based on level
        if ($slaEvent->level === 'breach') {
            $this->escalateBreach($slaEvent, $conversation);
        } elseif ($slaEvent->level === 'warning') {
            $this->escalateWarning($slaEvent, $conversation);
        }
    }

    private function escalateBreach(SupportSlaEvent $slaEvent, SupportConversation $conversation): void
    {
        $queue = $conversation->queue;

        // The escalation recipient is resolved once. Looking the user up inside
        // each block, as this did, meant every later block re-read the row and
        // silently operated on its own undefined copy when the first guard had
        // not run.
        $escalationUser = $queue && $queue->escalation_user_id
            ? User::find($queue->escalation_user_id)
            : null;

        // 1. Internal notification to escalation staff
        if ($escalationUser && $escalationUser->status->value === 'active') {
            DatabaseNotificationService::create(
                $escalationUser,
                'Incumplimiento SLA',
                $this->buildBreachMessage($slaEvent, $conversation),
                [
                    'type' => 'sla_breach',
                    'conversation_id' => $conversation->id,
                    'metric' => $slaEvent->metric,
                    'level' => 'breach',
                ]
            );
        }

        // 2. Email to escalation staff (if configured and user has email)
        if ($escalationUser && $escalationUser->email) {
            SendSupportEscalationEmail::dispatch(
                $escalationUser->id,
                $slaEvent->id,
                'breach'
            );
        }

        // 3. Telegram escalation if enabled
        if ($queue && $queue->telegram_escalation_enabled) {
            $this->sendTelegramEscalation($slaEvent, $conversation);
        }
    }

    private function escalateWarning(SupportSlaEvent $slaEvent, SupportConversation $conversation): void
    {
        // For warnings, only send internal notification to assigned staff
        if ($conversation->assigned_to_user_id) {
            $assignee = User::find($conversation->assigned_to_user_id);
            if ($assignee && $assignee->status->value === 'active') {
                DatabaseNotificationService::create(
                    $assignee,
                    'Advertencia SLA',
                    $this->buildWarningMessage($slaEvent, $conversation),
                    [
                        'type' => 'sla_warning',
                        'conversation_id' => $conversation->id,
                        'metric' => $slaEvent->metric,
                        'level' => 'warning',
                    ]
                );
            }
        }
    }

    private function buildBreachMessage(SupportSlaEvent $slaEvent, SupportConversation $conversation): string
    {
        $metricLabels = [
            'first_response' => 'Primera respuesta',
            'next_response' => 'Siguiente respuesta',
            'resolution' => 'Resolución',
        ];

        $metricLabel = $metricLabels[$slaEvent->metric] ?? $slaEvent->metric;
        $dueAt = $slaEvent->due_at?->format('d/m/Y H:i') ?? 'desconocido';

        return "Incumplimiento de SLA: {$metricLabel} vencida el {$dueAt}.\nConversación: {$conversation->subject} (#{$conversation->id})";
    }

    private function buildWarningMessage(SupportSlaEvent $slaEvent, SupportConversation $conversation): string
    {
        $metricLabels = [
            'first_response' => 'Primera respuesta',
            'next_response' => 'Siguiente respuesta',
            'resolution' => 'Resolución',
        ];

        $metricLabel = $metricLabels[$slaEvent->metric] ?? $slaEvent->metric;
        $dueAt = $slaEvent->due_at?->format('d/m/Y H:i') ?? 'desconocido';

        return "Advertencia SLA: {$metricLabel} por vencer el {$dueAt}.\nConversación: {$conversation->subject} (#{$conversation->id})";
    }

    private function sendTelegramEscalation(SupportSlaEvent $slaEvent, SupportConversation $conversation): void
    {
        // An endpoint scoped to this conversation, plus every admin-wide one.
        // Fetching all endpoints regardless of scope would route a private
        // conversation's breach into the general operations group.
        $endpoints = TelegramEndpoint::where('enabled', true)
            ->where(function ($query) use ($conversation): void {
                $query->whereNull('conversation_id')
                    ->orWhere('conversation_id', $conversation->id);
            })
            ->whereJsonContains('event_preferences', 'support_sla_breach')
            ->get();

        foreach ($endpoints as $endpoint) {
            $message = "🚨 *Incumplimiento SLA*\n\n".
                $this->buildBreachMessage($slaEvent, $conversation).
                "\n\n<a href='".config('app.url')."/soporte/{$conversation->id}\">Ver conversación</a>";

            SendTelegramMessage::dispatch($endpoint->id, $message);
        }
    }
}
