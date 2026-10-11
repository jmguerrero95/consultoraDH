<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Receivables\ReceivablesService;
use App\Models\AuditEvent;
use App\Models\AutomationRule;
use App\Models\AutomationRuleCursor;
use App\Models\AutomationRun;
use App\Models\ClientDocumentRequest;
use App\Models\OperationalTask;
use App\Models\SupportSlaEvent;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class DispatchAutomations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 120;

    public function handle(): void
    {
        // 1. Event-driven rules (audit_action)
        $this->dispatchAuditActionRules();

        // 2. Scheduled rules
        $this->dispatchScheduledRules();

        // 3. Due date rules (task_due, document_request_due, receivable_overdue)
        $this->dispatchDueRules();

        // 4. SLA breach rules
        $this->dispatchSlaBreachRules();
    }

    private function dispatchAuditActionRules(): void
    {
        $rules = AutomationRule::where('active', true)
            ->where('trigger_type', 'audit_action')
            ->whereHas('actions', function ($q) {
                $q->where('active', true);
            })
            ->with(['actions' => function ($q) {
                $q->where('active', true)->orderBy('position');
            }])
            ->chunkById(50, function ($rules) {
                foreach ($rules as $rule) {
                    $this->processAuditActionRule($rule);
                }
            });
    }

    private function processAuditActionRule(AutomationRule $rule): void
    {
        $cursor = AutomationRuleCursor::firstOrCreate(
            ['automation_rule_id' => $rule->id, 'source' => 'audit_events'],
            ['last_source_id' => 0]
        );

        $actionName = $rule->trigger_config['action_name'] ?? null;
        if (! $actionName) {
            return;
        }

        $events = AuditEvent::where('action', $actionName)
            ->where('id', '>', $cursor->last_source_id)
            ->orderBy('id')
            ->limit(100)
            ->get();

        foreach ($events as $event) {
            $run = $this->findOrCreateAutomationRun($rule, [
                'audit_event_id' => $event->id,
                'action' => $event->action,
                'subject_type' => $event->subject_type,
                'subject_id' => $event->subject_id,
                'metadata' => $event->metadata,
                // Actions address the subject by its own id, so the keys they
                // read have to sit at the top level of the snapshot instead of
                // nested under `metadata`. Left nested, `conversation_id` is
                // never found and every support action silently does nothing
                // while still reporting success.
                'conversation_id' => $this->auditConversationId($event),
                'client_id' => $event->metadata['client_id'] ?? null,
            ], "audit:{$event->id}");

            if ($run->wasRecentlyCreated) {
                if ($this->evaluateConditions($rule, $event)) {
                    ExecuteAutomationRun::dispatch($run->id);
                } else {
                    $run->update(['status' => 'skipped', 'finished_at' => now()]);
                }
            }

            $cursor->update(['last_source_id' => $event->id]);
        }

        if ($events->isNotEmpty()) {
            $cursor->update(['last_source_id' => $events->last()->id]);
        }
    }

    /**
     * The conversation an audit event is about, or null.
     *
     * Two ways of naming it are honoured, because both occur in practice: an
     * event that carries the conversation id in its metadata, and an event whose
     * subject *is* the conversation — the shape a "message received on this
     * thread" event naturally has.
     */
    private function auditConversationId(AuditEvent $event): ?int
    {
        $fromMetadata = $event->metadata['conversation_id'] ?? null;

        if ($fromMetadata !== null) {
            return (int) $fromMetadata;
        }

        $subjectType = (string) $event->subject_type;

        if (str_ends_with($subjectType, 'SupportConversation')) {
            return (int) $event->subject_id;
        }

        return null;
    }

    /**
     * Create the run for one occurrence, or return the run that already exists.
     *
     * There used to be two methods with this name: one for audit triggers that
     * evaluated conditions and advanced a cursor, and a generic one for the
     * scheduled triggers. PHP does not allow the duplicate, so loading this
     * class was a fatal and the whole dispatch command exited 255.
     *
     * Returning the run lets the audit caller inspect `wasRecentlyCreated` and
     * apply its own conditions; the scheduled callers simply dispatch.
     */
    private function findOrCreateAutomationRun(AutomationRule $rule, array $triggerSnapshot, string $occurrenceKey): AutomationRun
    {
        return AutomationRun::firstOrCreate(
            ['automation_rule_id' => $rule->id, 'occurrence_key' => $occurrenceKey],
            [
                'status' => 'pending',
                'trigger_snapshot' => $triggerSnapshot,
                'started_at' => now(),
            ]
        );
    }

    private function dispatchScheduledRules(): void
    {
        $now = now();

        $ruleIds = AutomationRule::where('active', true)
            ->where('trigger_type', 'schedule')
            ->where('next_run_at', '<=', $now)
            ->whereHas('actions', function ($q) {
                $q->where('active', true);
            })
            ->pluck('id');

        foreach ($ruleIds as $ruleId) {
            // The transaction decides whether a run was created and for which
            // occurrence; the execution is dispatched only once that work has
            // committed.
            //
            // Dispatching from inside the transaction would let a worker pick the
            // run up while the row is still uncommitted, so it would read a
            // `pending` run its own connection cannot see and fail. The
            // transaction therefore returns the id to execute and does nothing
            // else with it here.
            $runId = DB::transaction(function () use ($ruleId, $now): ?int {
                $rule = AutomationRule::query()
                    ->whereKey($ruleId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $rule->active) {
                    return null;
                }

                // Re-read under the lock: the due check above ran unlocked, and
                // a concurrent dispatch may have already moved the rule forward.
                $occurrence = $rule->next_run_at;

                if (! $occurrence || $occurrence->gt($now)) {
                    return null;
                }

                // Keyed on the occurrence itself, so one occurrence can never
                // produce two runs while a later one still can.
                $occurrenceKey = "schedule:{$rule->id}:{$occurrence->toISOString()}";

                $run = AutomationRun::firstOrCreate(
                    ['automation_rule_id' => $rule->id, 'occurrence_key' => $occurrenceKey],
                    [
                        'status' => 'pending',
                        'trigger_snapshot' => [
                            'scheduled_at' => $occurrence->toISOString(),
                            'trigger_config' => $rule->trigger_config,
                        ],
                        'started_at' => $now,
                    ]
                );

                // Advanced from the locked occurrence whether or not this
                // occurrence had already been processed, so a rule left due by an
                // interrupted pass still moves forward instead of re-firing.
                $nextRun = $this->calculateNextRun($rule, $occurrence);
                if ($nextRun) {
                    $rule->update(['next_run_at' => $nextRun]);
                }

                if (! $run->wasRecentlyCreated) {
                    return null;
                }

                if (! $this->evaluateScheduleConditions($rule)) {
                    $run->update(['status' => 'skipped', 'finished_at' => now()]);

                    return null;
                }

                return $run->id;
            });

            if ($runId !== null) {
                ExecuteAutomationRun::dispatch($runId);
            }
        }
    }

    private function calculateNextRun(AutomationRule $rule, Carbon $from): ?Carbon
    {
        $config = $rule->trigger_config;
        $frequency = $config['frequency'] ?? 'daily';
        $time = $config['time'] ?? '00:00';

        $next = Carbon::createFromFormat('H:i', $time)->setTimezone(config('app.timezone'));

        switch ($frequency) {
            case 'daily':
                if ($next <= $from) {
                    $next->addDay();
                }
                break;
            case 'weekly':
                $dayOfWeek = $config['day_of_week'] ?? 0;
                $next->dayOfWeek = $dayOfWeek;
                if ($next <= $from) {
                    $next->addWeek();
                }
                break;
            case 'monthly':
                $dayOfMonth = $config['day_of_month'] ?? 1;
                $next->day = min($dayOfMonth, $next->daysInMonth);
                if ($next <= $from) {
                    $next->addMonthNoOverflow();
                }
                break;
        }

        return $next;
    }

    private function evaluateScheduleConditions(AutomationRule $rule): bool
    {
        $conditions = $rule->condition_config;

        // Schedule triggers MUST NOT have dynamic conditions.
        // Non-empty condition_config is a configuration error.
        if (! empty($conditions)) {
            // Silently become false (as per spec: reject/block)
            return false;
        }

        return true;
    }

    private function dispatchDueRules(): void
    {
        // Task due
        $this->dispatchTaskDueRules();

        // Document request due
        $this->dispatchDocumentRequestDueRules();

        // Receivable overdue
        $this->dispatchReceivableOverdueRules();
    }

    private function dispatchTaskDueRules(): void
    {
        $rules = AutomationRule::where('active', true)
            ->where('trigger_type', 'task_due')
            ->whereHas('actions', function ($q) {
                $q->where('active', true);
            })
            ->with(['actions' => function ($q) {
                $q->where('active', true)->orderBy('position');
            }])
            ->get();

        foreach ($rules as $rule) {
            $daysBefore = $rule->trigger_config['days_before'] ?? 0;
            $dueDate = now()->addDays($daysBefore)->toDateString();

            $tasks = OperationalTask::where('due_on', $dueDate)
                ->where('status', '!=', 'completed')
                ->where('status', '!=', 'cancelled')
                ->with(['assignee'])
                ->get();

            foreach ($tasks as $task) {
                $run = $this->findOrCreateAutomationRun($rule, $task->toArray(), "task_due:{$task->id}:{$dueDate}");

                if ($run->wasRecentlyCreated) {
                    ExecuteAutomationRun::dispatch($run->id);
                }
            }
        }
    }

    private function dispatchDocumentRequestDueRules(): void
    {
        $rules = AutomationRule::where('active', true)
            ->where('trigger_type', 'document_request_due')
            ->whereHas('actions', function ($q) {
                $q->where('active', true);
            })
            ->with(['actions' => function ($q) {
                $q->where('active', true)->orderBy('position');
            }])
            ->get();

        foreach ($rules as $rule) {
            $daysBefore = $rule->trigger_config['days_before'] ?? 0;
            $dueDate = now()->addDays($daysBefore)->toDateString();

            $requests = ClientDocumentRequest::where('due_on', $dueDate)
                ->where('status', '!=', 'approved')
                ->where('status', '!=', 'rejected')
                ->where('status', '!=', 'cancelled')
                ->with(['client', 'documentType'])
                ->get();

            foreach ($requests as $request) {
                $run = $this->findOrCreateAutomationRun($rule, $request->toArray(), "document_due:{$request->id}:{$dueDate}");

                if ($run->wasRecentlyCreated) {
                    ExecuteAutomationRun::dispatch($run->id);
                }
            }
        }
    }

    private function dispatchReceivableOverdueRules(): void
    {
        $rules = AutomationRule::where('active', true)
            ->where('trigger_type', 'receivable_overdue')
            ->whereHas('actions', function ($q) {
                $q->where('active', true);
            })
            ->with(['actions' => function ($q) {
                $q->where('active', true)->orderBy('position');
            }])
            ->get();

        foreach ($rules as $rule) {
            $minDays = $rule->trigger_config['min_days_overdue'] ?? 1;
            $asOf = now()->subDays($minDays)->toDateString();

            // Batch size, overridable per rule so a large portfolio can be
            // walked in smaller slices. Clamped so a hand-edited config cannot
            // ask for an unbounded page.
            $perPage = max(1, min(500, (int) ($rule->trigger_config['per_page'] ?? 500)));

            // ReceivablesService::list() is the authoritative source: the ageing
            // figures and the overdue balance are computed there, not re-derived
            // here. Every page is walked, because stopping after the first would
            // silently skip every client past the page size.
            $page = 1;
            do {
                $receivables = app(ReceivablesService::class)->list([
                    'overdue' => true,
                    'as_of' => $asOf,
                    'outstanding_only' => true,
                ], $page, $perPage);

                foreach ($receivables['items'] as $clientData) {
                    $run = $this->findOrCreateAutomationRun($rule, $clientData, "receivable_overdue:{$clientData['client_id']}:{$asOf}");

                    if ($run->wasRecentlyCreated) {
                        ExecuteAutomationRun::dispatch($run->id);
                    }
                }

                $page++;
            } while ($page <= $receivables['last_page']);
        }
    }

    private function dispatchSlaBreachRules(): void
    {
        $rules = AutomationRule::where('active', true)
            ->where('trigger_type', 'support_sla_breach')
            ->whereHas('actions', function ($q) {
                $q->where('active', true);
            })
            ->with(['actions' => function ($q) {
                $q->where('active', true)->orderBy('position');
            }])
            ->get();

        foreach ($rules as $rule) {
            $metric = $rule->trigger_config['metric'] ?? 'first_response';
            $level = $rule->trigger_config['level'] ?? 'breach';

            // Find SLA events that match and haven't triggered this rule
            $slaEvents = SupportSlaEvent::where('metric', $metric)
                ->where('level', $level)
                ->where('emitted_at', '>=', now()->subHour())
                ->with('conversation')
                ->get();

            foreach ($slaEvents as $event) {
                $occurrenceKey = "sla:{$event->id}";
                $run = AutomationRun::firstOrCreate(
                    ['automation_rule_id' => $rule->id, 'occurrence_key' => $occurrenceKey],
                    [
                        'status' => 'pending',
                        'trigger_snapshot' => [
                            'sla_event_id' => $event->id,
                            'metric' => $metric,
                            'level' => $level,
                        ],
                        'started_at' => now(),
                    ]
                );

                if ($run->wasRecentlyCreated) {
                    ExecuteAutomationRun::dispatch($run->id);
                }
            }
        }
    }

    private function evaluateConditions(AutomationRule $rule, AuditEvent $event): bool
    {
        $conditions = $rule->condition_config;
        if (empty($conditions)) {
            return true;
        }

        // Simple AND-only conditions evaluation
        foreach ($conditions as $condition) {
            if (! $this->evaluateCondition($condition, $event)) {
                return false;
            }
        }

        return true;
    }

    private function evaluateCondition(array $condition, AuditEvent $event): bool
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? 'eq';
        $value = $condition['value'] ?? null;

        if (! $field) {
            return true;
        }

        $eventValue = $this->getEventFieldValue($event, $field);
        if ($eventValue === null) {
            return false;
        }

        return match ($operator) {
            'eq' => $eventValue == $value,
            'neq' => $eventValue != $value,
            'in' => is_array($value) && in_array($eventValue, $value),
            'gte' => $eventValue >= $value,
            'lte' => $eventValue <= $value,
            'exists' => $eventValue !== null,
            default => true,
        };
    }

    private function getEventFieldValue(AuditEvent $event, string $field): mixed
    {
        return match ($field) {
            'action_name' => $event->action,
            'subject_type' => $event->subject_type,
            'subject_id' => $event->subject_id,
            default => $event->metadata?->{$field} ?? null,
        };
    }
}
