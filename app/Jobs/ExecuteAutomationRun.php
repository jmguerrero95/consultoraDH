<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Support\Actions\AssignConversation;
use App\Domain\Support\Actions\ChangeConversationPriority;
use App\Domain\Support\Actions\ChangeConversationQueue;
use App\Domain\Support\SupportConversationPriority;
use App\Mail\AutomationEmail;
use App\Models\AutomationAction;
use App\Models\AutomationActionRun;
use App\Models\AutomationRun;
use App\Models\Client;
use App\Models\OperationalTask;
use App\Models\SupportConversation;
use App\Models\SupportQueue;
use App\Models\TelegramEndpoint;
use App\Models\User;
use App\Notifications\InternalAutomationNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        $run = AutomationRun::with(['rule.actions', 'rule.owner'])->find($this->automationRunId);

        if (! $run) {
            Log::warning('Automation run not found', ['run_id' => $this->automationRunId]);

            return;
        }

        if (! in_array($run->status, ['pending', 'partial', 'failed'], true)) {
            return;
        }

        // Check owner permissions (explicit comparison, not loose)
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

            // Skip already succeeded actions on retry
            if ($actionRun->status === 'succeeded') {
                continue;
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

        if (! $owner) {
            return false;
        }

        // Explicit comparison - inactive/missing owner blocks execution
        if ($owner->status->value !== 'active') {
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

        if (! $owner || $owner->status->value !== 'active') {
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
            'internal_notification' => true,
            default => false, // Unknown action type: reject/block
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
                // Unknown action type: reject/block (never permissive default)
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
                $user->notify(new InternalAutomationNotification(
                    $title,
                    $message,
                    ['automation_run_id' => $run->id]
                ));
            }
        }
    }

    private function executeCreateTask(AutomationAction $action, AutomationRun $run): void
    {
        $config = $action->config;
        $assignee = User::find($config['assignee_user_id'] ?? 0);

        if (! $assignee || $assignee->account_type !== 'staff' || $assignee->status->value !== 'active') {
            throw new \InvalidArgumentException('Invalid or inactive assignee');
        }

        // Use A05's authoritative column names
        OperationalTask::create([
            'title' => $config['title'],
            'description' => $config['description'] ?? '',
            'assigned_to' => $assignee->id,
            'priority' => $config['priority'] ?? 'normal',
            'due_on' => $config['due_on'],
            'created_by' => $run->rule->owner_user_id,
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

        // The same silent success, in a different shape: an empty recipient list
        // meant the `foreach` below did nothing and the action was recorded as
        // sent. Every branch above can legitimately come back empty — a trigger
        // with no client, an unassigned conversation, a queue with no fallback,
        // a user id that no longer exists — and each of those is a configuration
        // the rule cannot actually carry out.
        if ($recipients === []) {
            throw new \InvalidArgumentException(
                "The rule did not resolve any recipient for the '{$recipientType}' recipient type"
            );
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
        if (! $endpoint || ! $endpoint->enabled) {
            throw new \InvalidArgumentException('Telegram endpoint not found or disabled');
        }

        SendTelegramMessage::dispatch($endpointId, $message);
    }

    /**
     * The conversation an action is meant to act on, or an exception.
     *
     * ## Why this throws instead of returning
     *
     * The vocabulary lets an administrator combine any trigger with any action,
     * and an event-driven trigger does not always carry a conversation: a rule
     * watching `client_created` can be given an action that moves a conversation
     * between queues. There is then nothing to act on.
     *
     * That used to fall out of the `if` and return normally, and the action was
     * then recorded as `succeeded` having changed nothing. The run reported work
     * done that never happened, and nothing anywhere said the rule could not do
     * what it was asked to do.
     *
     * Failing is the honest outcome. The action run becomes `failed`, the run
     * becomes `partial`, and the reason names the missing context. The domain
     * actions below are unchanged and still do the changing; this only decides
     * whether they are called with something real.
     */
    private function requireTriggerConversation(AutomationRun $run): SupportConversation
    {
        $conversationId = $run->trigger_snapshot['conversation_id'] ?? null;

        if ($conversationId === null) {
            throw new \InvalidArgumentException(
                'The trigger for this rule did not provide a conversation to act on'
            );
        }

        $conversation = SupportConversation::find($conversationId);

        if ($conversation === null) {
            throw new \InvalidArgumentException(
                'The conversation named by the trigger no longer exists'
            );
        }

        return $conversation;
    }

    private function executeAssignSupportQueue(AutomationAction $action, AutomationRun $run): void
    {
        $conversation = $this->requireTriggerConversation($run);

        // The domain action does the changing; it is unchanged.
        app(ChangeConversationQueue::class)->execute(
            $conversation,
            SupportQueue::findOrFail($action->config['queue_id']),
            User::find($run->rule->owner_user_id),
            null
        );
    }

    private function executeAssignSupportUser(AutomationAction $action, AutomationRun $run): void
    {
        $config = $action->config;
        $userId = $config['user_id'];

        $user = User::find($userId);
        if (! $user || $user->account_type !== 'staff' || $user->status->value !== 'active') {
            throw new \InvalidArgumentException('Invalid or inactive user');
        }

        $conversation = $this->requireTriggerConversation($run);

        // The domain action does the assigning; it is unchanged.
        app(AssignConversation::class)->execute($conversation, User::find($userId), User::find($run->rule->owner_user_id));
    }

    private function executeSetSupportPriority(AutomationAction $action, AutomationRun $run): void
    {
        $config = $action->config;
        $priority = $config['priority'];

        if (! in_array($priority, ['normal', 'high', 'urgent'])) {
            throw new \InvalidArgumentException('Invalid priority');
        }

        $conversation = $this->requireTriggerConversation($run);

        // The domain action does the changing; it is unchanged.
        app(ChangeConversationPriority::class)->execute($conversation, SupportConversationPriority::from($priority));
    }

    private function notifyAdmins(string $message, AutomationRun $run): void
    {
        // Log for now; could be extended to actual admin notifications
        Log::warning($message, [
            'run_id' => $run->id,
            'rule_id' => $run->rule_id,
            'rule_name' => $run->rule->name ?? 'unknown',
        ]);
    }
}
