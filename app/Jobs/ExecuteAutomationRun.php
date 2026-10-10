<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Notifications\DatabaseNotificationService;
use App\Domain\Receivables\ReceivablesService;
use App\Domain\Support\Actions\AssignConversation;
use App\Domain\Support\Actions\ChangeConversationPriority;
use App\Domain\Support\Actions\ChangeConversationQueue;
use App\Jobs\SendTelegramMessage;
use App\Models\AutomationAction;
use App\Models\AutomationActionRun;
use App\Models\AutomationRun;
use App\Models\Client;
use App\Models\OperationalTask;
use App\Models\SupportConversation;
use App\Models\TelegramEndpoint;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ExecuteAutomationRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 120;

    public function __construct(
        public int $automationRunId
    ) {}

    public function handle(): void
    {
        $run = AutomationRun::with(['rule.actions'])->find($this->automationRunId);

        if (! $run) {
            Log::warning('Automation run not found', ['run_id' => $this->automationRunId]);
            return;
        }

        if ($run->status !== 'pending') {
            return;
        }

        // Check owner permissions
        if (! $this->checkOwnerPermissions($run)) {
            $run->update(['status' => 'blocked', 'finished_at' => now(), 'error_code' => 'owner_permissions_lost']);
            $this->notifyAdmins('Automation blocked: owner permissions lost', $run);
            return;
        }

        $run->update(['status' => 'running']);

        $allSucceeded = true;

        foreach ($run->rule->actions as $action) {
            $actionRun = AutomationActionRun::firstOrCreate(
                ['automation_run_id' => $run->id, 'automation_action_id' => $action->id],
                ['status' => 'pending', 'started_at' => now()]
            );

            if ($actionRun->status === 'succeeded') {
                continue; // Already succeeded, skip
            }

            // Check action-specific permissions before execution
            if (! $this->checkActionPermissions($run, $action)) {
                $actionRun->update([
                    'status' => 'blocked',
                    'finished_at' => now(),
                    'error_code' => 'action_permission_lost',
                ]);
                $allSucceeded = false;
                continue;
            }

            try {
                $this->executeAction($action, $run, $actionRun);
                $actionRun->update(['status' => 'succeeded', 'finished_at' => now()]);
            } catch (\Throwable $e) {
                Log::error('Automation action failed', [
                    'run_id' => $run->id,
                    'action_id' => $action->id,
                    'error' => $e->getMessage(),
                ]);
                $actionRun->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'error_code' => $e->getCode() ?? 'unknown',
                ]);
                $allSucceeded = false;
            }
        }

        $run->update([
            'status' => $allSucceeded ? 'succeeded' : 'partial',
            'finished_at' => now(),
        ]);
    }

    private function checkOwnerPermissions(AutomationRun $run): bool
    {
        $owner = $run->rule->owner;

        if (! $owner || !$owner->status->value === 'active') {
            return false;
        }

        if (! $owner->hasPermissionTo('automations.manage')) {
            return false;
        }

        return true;
    }

    private function checkActionPermissions(AutomationRun $run, AutomationAction $action): bool
    {
        $owner = $run->rule->owner;

        if (! $owner || !$owner->status->value === 'active') {
            return false;
        }

        if (! $owner->hasPermissionTo('automations.manage')) {
            return false;
        }

        // Check action-specific permissions
        return match ($action->action_type) {
            'create_task' => $run->rule->owner->hasPermissionTo('tasks.manage'),
            'send_email' => $run->rule->owner->hasPermissionTo('notification_channels.manage'),
            'send_telegram' => $run->rule->owner->hasPermissionTo('notification_channels.manage'),
            'assign_support_queue' => $run->rule->owner->hasPermissionTo('support.manage_queues'),
            'assign_support_user' => $run->rule->owner->hasPermissionTo('support.assign'),
            'set_support_priority' => $run->rule->owner->hasPermissionTo('support.assign'),
            'internal_notification' => true, // No additional permission needed
            default => true,
        };
    }

    private function executeAction(AutomationAction $action, AutomationRun $run, AutomationActionRun $actionRun): void
    {
        switch ($action->action_type) {
            case 'internal_notification':
                $this->executeInternalNotification($action, $run);
                break;
            case 'create_task':
                $this->executeCreateTask($action, $run);
                break;
            case 'send_email':
                $this->executeSendEmail($action, $run);
                break;
            case 'send_telegram':
                $this->executeSendTelegram($action, $run);
                break;
            case 'assign_support_queue':
                $this->executeAssignSupportQueue($action, $run);
                break;
            case 'assign_support_user':
                $this->executeAssignSupportUser($action, $run);
                break;
            case 'set_support_priority':
                $this->executeSetSupportPriority($action, $run);
                break;
            default:
                throw new \InvalidArgumentException("Unknown action type: {$action->action_type}");
        }
    }

    private function executeInternalNotification(AutomationAction $action, AutomationRun $run): void
    {
        $config = $action->config;
        $userIds = $config['user_ids'] ?? [];
        $title = $config['title'] ?? 'Notificación automática';
        $message = $config['message'] ?? '';

        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if ($user) {
                DatabaseNotificationService::create(
                    $user,
                    $title,
                    $message,
                    ['automation_run_id' => $run->id]
                );
            }
        }
    }

    private function executeCreateTask(AutomationAction $action, AutomationRun $run): void
    {
        $config = $action->config;
        $assignee = User::find($config['assignee_user_id'] ?? 0);

        if (! $assignee || $assignee->account_type !== 'staff' || !$assignee->status->value === 'active') {
            throw new \InvalidArgumentException('Invalid or inactive assignee');
        }

        // Use A05's authoritative column names
        OperationalTask::create([
            'title' => $config['title'],
            'description' => $config['description'] ?? '',
            'assigned_to' => $assignee->id, // A05 uses assigned_to, not assignee_user_id
            'priority' => $config['priority'] ?? 'normal',
            'due_on' => $config['due_on'],
            'created_by' => $run->rule->owner_user_id, // A05 uses created_by
        ]);
    }

    private function executeSendEmail(AutomationAction $action, AutomationRun $run): void
    {
        $config = $action->config;
        $recipientType = $config['recipient_type'] ?? 'specific_user';
        $subject = $config['subject'];
        $body = $config['body'];

        $recipients = [];

        switch ($recipientType) {
            case 'client':
                if ($run->trigger_snapshot['client_id'] ?? null) {
                    $client = Client::with('user')->find($run->trigger_snapshot['client_id']);
                    if ($client && $client->user) {
                        $recipients[] = $client->user->email;
                    }
                }
                break;
            case 'assigned_staff':
                if ($run->trigger_snapshot['conversation_id'] ?? null) {
                    $conversation = SupportConversation::with('assignee')->find($run->trigger_snapshot['conversation_id']);
                    if ($conversation && $conversation->assignee) {
                        $recipients[] = $conversation->assignee->email;
                    }
                }
                break;
            case 'queue_fallback':
                if ($run->trigger_snapshot['conversation_id'] ?? null) {
                    $conversation = SupportConversation::with('queue.fallbackUser')->find($run->trigger_snapshot['conversation_id']);
                    if ($conversation && $conversation->queue && $conversation->queue->fallbackUser) {
                        $recipients[] = $conversation->queue->fallbackUser->email;
                    }
                }
                break;
            case 'specific_user':
                if ($config['specific_user_id'] ?? null) {
                    $user = User::find($config['specific_user_id']);
                    if ($user) {
                        $recipients[] = $user->email;
                    }
                }
                break;
        }

        foreach ($recipients as $email) {
            Mail::to($email)->queue(new AutomationEmail($subject, $body));
        }
    }

    private function executeSendTelegram(AutomationAction $action, AutomationRun $run): void
    {
        $config = $action->config;
        $endpointId = $config['endpoint_id'];
        $message = $config['message'];

        $endpoint = TelegramEndpoint::find($endpointId);
        if (! $endpoint || !$endpoint->enabled) {
            throw new \InvalidArgumentException('Telegram endpoint not found or disabled');
        }

        SendTelegramMessage::dispatch($endpointId, $message);
    }

    private function executeAssignSupportQueue(AutomationAction $action, AutomationRun $run): void
    {
        // Use the domain action to ensure consistent behavior
        if ($run->trigger_snapshot['conversation_id'] ?? null) {
            $conversation = SupportConversation::find($run->trigger_snapshot['conversation_id']);
            if ($conversation) {
                app(\App\Domain\Support\Actions\ChangeConversationQueue::class)->execute(
                    $conversation,
                    \App\Models\SupportQueue::findOrFail($action->config['queue_id']),
                    \App\Models\User::find($run->rule->owner_user_id),
                    null
                );
            }
        }
    }

    private function executeAssignSupportUser(AutomationAction $action, AutomationRun $run): void
    {
        $config = $action->config;
        $userId = $config['user_id'];

        $user = User::find($userId);
        if (! $user || $user->account_type !== 'staff' || !$user->status->value === 'active') {
            throw new \InvalidArgumentException('Invalid or inactive user');
        }

        if ($run->trigger_snapshot['conversation_id'] ?? null) {
            $conversation = SupportConversation::find($run->trigger_snapshot['conversation_id']);
            if ($conversation) {
                // Use the domain action for proper validation
                app(AssignConversation::class)->execute($conversation, \App\Models\User::find($userId), \App\Models\User::find($run->rule->owner_user_id));
            }
        }
    }

    private function executeSetSupportPriority(AutomationAction $action, AutomationRun $run): void
    {
        $config = $action->config;
        $priority = $config['priority'];

        if (! in_array($priority, ['normal', 'high', 'urgent'])) {
            throw new \InvalidArgumentException('Invalid priority');
        }

        if ($run->trigger_snapshot['conversation_id'] ?? null) {
            $conversation = SupportConversation::find($run->trigger_snapshot['conversation_id']);
            if ($conversation) {
                app(\App\Domain\Support\Actions\ChangeConversationPriority::class)->execute($conversation, \App\Domain\Support\SupportConversationPriority::from($priority));
            }
        }
    }
}