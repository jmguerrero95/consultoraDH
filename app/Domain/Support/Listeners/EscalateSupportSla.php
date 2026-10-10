<?php

declare(strict_types=1);

namespace App\Domain\Support\Listeners;

use App\Domain\Support\Events\SupportSlaEventEmitted;
use App\Jobs\SendSupportFallbackEmail;
use App\Jobs\SendTelegramMessage;
use App\Models\Client;
use App\Models\SupportConversation;
use App\Models\SupportQueue;
use App\Models\TelegramEndpoint;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EscalateSupportSla
{
    public function handle(SupportSlaEventEmitted $event): void
    {
        $slaEvent = $event->slaEvent;
        $conversation = $slaEvent->conversation;

        if (!$conversation) {
            return;
        }

        // Escalate based on level
        if ($slaEvent->level === 'breach') {
            $this->escalateBreach($slaEvent, $conversation);
        } elseif ($slaEvent->level === 'warning') {
            $this->escalateWarning($slaEvent, $conversation);
        }
    }

    private function escalateBreach(\App\Models\SupportSlaEvent $slaEvent, SupportConversation $conversation): void
    {
        $queue = $conversation->queue;

        // 1. Internal notification to escalation staff
        if ($queue && $queue->escalation_user_id) {
            $escalationUser = \App\Models\User::find($queue->escalation_user_id);
            if ($escalationUser && $escalationUser->status->is('active')) {
                \App\Domain\Notifications\DatabaseNotificationService::create(
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
        if ($queue && $queue->escalation_user_id) {
            $escalationUser = \App\Models\User::find($queue->escalation_user_id);
            if ($escalationUser && $escalationUser->email) {
                \App\Jobs\SendSupportEscalationEmail::dispatch(
                    $escalationUser->id,
                    $slaEvent->id,
                    'breach'
                );
            }
        }

        // 3. Telegram escalation if enabled
        if ($queue && $queue->telegram_escalation_enabled) {
            $this->sendTelegramEscalation($slaEvent, $conversation);
        }
    }

    private function escalateWarning(\App\Models\SupportSlaEvent $slaEvent, \App\Models\SupportConversation $conversation): void
    {
        // For warnings, only send internal notification to assigned staff
        if ($conversation->assigned_to_user_id) {
            $assignee = \App\Models\User::find($conversation->assigned_to_user_id);
            if ($assignee && $assignee->status->is('active')) {
                \App\Domain\Notifications\DatabaseNotificationService::create(
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

    private function buildBreachMessage(\App\Models\SupportSlaEvent $slaEvent, \App\Models\SupportConversation $conversation): string
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

    private function buildWarningMessage(\App\Models\SupportSlaEvent $slaEvent, \App\Models\SupportConversation $conversation): string
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

    private function sendTelegramEscalation(\App\Models\SupportSlaEvent $slaEvent, \App\Models\SupportConversation $conversation): void
    {
        $endpoints = TelegramEndpoint::where('enabled', true)
            ->whereJsonContains('event_preferences', 'support_sla_breach')
            ->get();

        foreach ($endpoints as $endpoint) {
            $message = "🚨 *Incumplimiento SLA*\n\n" .
                $this->buildBreachMessage($slaEvent, $conversation) .
                "\n\n<a href='" . config('app.url') . "/soporte/{$conversation->id}\">Ver conversación</a>";

            \App\Jobs\SendTelegramMessage::dispatch($endpoint->id, $message);
        }
    }
}