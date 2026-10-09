<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\AutomationAction;
use App\Models\AutomationRule;
use App\Models\AutomationRuleCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AutomationController extends Controller
{
    public function vocabulary(Request $request): JsonResponse
    {
        return response()->json([
            'triggers' => [
                'audit_action' => [
                    'label' => 'Acción de auditoría',
                    'fields' => [
                        'action_name' => ['type' => 'string', 'label' => 'Nombre de la acción de auditoría'],
                    ],
                ],
                'schedule' => [
                    'label' => 'Programado',
                    'fields' => [
                        'frequency' => ['type' => 'string', 'label' => 'Frecuencia', 'options' => ['daily', 'weekly', 'monthly']],
                        'time' => ['type' => 'string', 'label' => 'Hora local (HH:MM)'],
                        'day_of_week' => ['type' => 'integer', 'label' => 'Día de la semana (0-6)', 'required_if' => 'frequency=weekly'],
                        'day_of_month' => ['type' => 'integer', 'label' => 'Día del mes (1-31)', 'required_if' => 'frequency=monthly'],
                    ],
                ],
                'task_due' => [
                    'label' => 'Tarea por vencer',
                    'fields' => [
                        'days_before' => ['type' => 'integer', 'label' => 'Días antes del vencimiento', 'default' => 0],
                    ],
                ],
                'document_request_due' => [
                    'label' => 'Solicitud de documento por vencer',
                    'fields' => [
                        'days_before' => ['type' => 'integer', 'label' => 'Días antes del vencimiento', 'default' => 0],
                    ],
                ],
                'receivable_overdue' => [
                    'label' => 'Cobro vencido',
                    'fields' => [
                        'min_days_overdue' => ['type' => 'integer', 'label' => 'Mínimo días de mora'],
                    ],
                ],
                'support_sla_breach' => [
                    'label' => 'Incumplimiento SLA de soporte',
                    'fields' => [
                        'metric' => ['type' => 'string', 'label' => 'Métrica', 'options' => ['first_response', 'next_response', 'resolution']],
                        'level' => ['type' => 'string', 'label' => 'Nivel', 'options' => ['warning', 'breach']],
                    ],
                ],
            ],
            'conditions' => [
                'audit_action' => [
                    'field' => 'action_name',
                    'operators' => ['eq', 'neq', 'in'],
                ],
                'schedule' => [],
                'task_due' => [
                    'field' => 'priority',
                    'operators' => ['eq', 'neq', 'in'],
                ],
                'document_request_due' => [
                    'field' => 'document_type_id',
                    'operators' => ['eq', 'neq', 'in'],
                ],
                'receivable_overdue' => [
                    'field' => 'company_id',
                    'operators' => ['eq', 'neq', 'in'],
                ],
                'support_sla_breach' => [
                    'field' => 'queue_id',
                    'operators' => ['eq', 'neq', 'in'],
                ],
            ],
            'actions' => [
                'internal_notification' => [
                    'label' => 'Notificación interna',
                    'config' => [
                        'user_ids' => ['type' => 'array', 'label' => 'IDs de usuarios', 'items' => ['type' => 'integer']],
                        'title' => ['type' => 'string', 'label' => 'Título'],
                        'message' => ['type' => 'string', 'label' => 'Mensaje'],
                    ],
                ],
                'create_task' => [
                    'label' => 'Crear tarea',
                    'config' => [
                        'title' => ['type' => 'string', 'label' => 'Título'],
                        'description' => ['type' => 'string', 'label' => 'Descripción'],
                        'assignee_user_id' => ['type' => 'integer', 'label' => 'ID del responsable'],
                        'priority' => ['type' => 'string', 'label' => 'Prioridad', 'options' => ['low', 'normal', 'high', 'urgent']],
                        'due_on' => ['type' => 'string', 'label' => 'Fecha de vencimiento (YYYY-MM-DD)'],
                    ],
                ],
                'send_email' => [
                    'label' => 'Enviar correo',
                    'config' => [
                        'recipient_type' => ['type' => 'string', 'label' => 'Tipo de destinatario', 'options' => ['client', 'assigned_staff', 'queue_fallback', 'specific_user']],
                        'specific_user_id' => ['type' => 'integer', 'label' => 'ID de usuario específico'],
                        'subject' => ['type' => 'string', 'label' => 'Asunto'],
                        'body' => ['type' => 'string', 'label' => 'Cuerpo del mensaje'],
                    ],
                ],
                'send_telegram' => [
                    'label' => 'Enviar Telegram',
                    'config' => [
                        'endpoint_id' => ['type' => 'integer', 'label' => 'ID del endpoint de Telegram'],
                        'message' => ['type' => 'string', 'label' => 'Mensaje'],
                    ],
                ],
                'assign_support_queue' => [
                    'label' => 'Asignar cola de soporte',
                    'config' => [
                        'queue_id' => ['type' => 'integer', 'label' => 'ID de la cola'],
                    ],
                ],
                'assign_support_user' => [
                    'label' => 'Asignar usuario de soporte',
                    'config' => [
                        'user_id' => ['type' => 'integer', 'label' => 'ID del usuario'],
                    ],
                ],
                'set_support_priority' => [
                    'label' => 'Establecer prioridad de soporte',
                    'config' => [
                        'priority' => ['type' => 'string', 'label' => 'Prioridad', 'options' => ['normal', 'high', 'urgent']],
                    ],
                ],
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = AutomationRule::query()
            ->with(['owner', 'actions'])
            ->orderByDesc('created_at');

        if ($request->filled('active')) {
            $query->where('active', $request->boolean('active'));
        }

        if ($request->filled('trigger_type')) {
            $query->where('trigger_type', $request->input('trigger_type'));
        }

        $rules = $query->paginate($request->integer('per_page', 25));

        return response()->json($rules);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'trigger_type' => ['required', Rule::in([
                'audit_action', 'schedule', 'task_due',
                'document_request_due', 'receivable_overdue',
                'support_sla_breach',
            ])],
            'trigger_config' => 'required|array',
            'condition_config' => 'nullable|array',
            'actions' => 'required|array|min:1',
            'actions.*.action_type' => ['required', Rule::in([
                'internal_notification', 'create_task', 'send_email',
                'send_telegram', 'assign_support_queue', 'assign_support_user',
                'set_support_priority',
            ])],
            'actions.*.config' => 'required|array',
            'actions.*.position' => 'required|integer|min:0',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $rule = AutomationRule::create([
                'name' => $validated['name'],
                'description' => $validated['description'],
                'trigger_type' => $validated['trigger_type'],
                'trigger_config' => $validated['trigger_config'],
                'condition_config' => $validated['condition_config'] ?? [],
                'owner_user_id' => $request->user()->id,
                'active' => false,
            ]);

            foreach ($validated['actions'] as $action) {
                AutomationAction::create([
                    'automation_rule_id' => $rule->id,
                    'position' => $action['position'],
                    'action_type' => $action['action_type'],
                    'config' => $action['config'],
                    'active' => true,
                ]);
            }

            return response()->json($rule->load('actions'), 201);
        });
    }

    public function show(Request $request, AutomationRule $rule): JsonResponse
    {
        return response()->json($rule->load(['owner', 'actions', 'runs' => function ($q) {
            $q->latest()->limit(20);
        }]));
    }

    public function update(Request $request, AutomationRule $rule): JsonResponse
    {
        if ($rule->active) {
            return response()->json(['message' => 'No se puede editar una regla activa. Desactívela primero.'], 409);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'trigger_type' => ['sometimes', 'required', Rule::in([
                'audit_action', 'schedule', 'task_due',
                'document_request_due', 'receivable_overdue',
                'support_sla_breach',
            ])],
            'trigger_config' => 'sometimes|required|array',
            'condition_config' => 'nullable|array',
            'actions' => 'sometimes|required|array|min:1',
            'actions.*.action_type' => ['required', Rule::in([
                'internal_notification', 'create_task', 'send_email',
                'send_telegram', 'assign_support_queue', 'assign_support_user',
                'set_support_priority',
            ])],
            'actions.*.config' => 'required|array',
            'actions.*.position' => 'required|integer|min:0',
        ]);

        return DB::transaction(function () use ($rule, $validated) {
            $rule->update([
                'name' => $validated['name'] ?? $rule->name,
                'description' => $validated['description'] ?? $rule->description,
                'trigger_type' => $validated['trigger_type'] ?? $rule->trigger_type,
                'trigger_config' => $validated['trigger_config'] ?? $rule->trigger_config,
                'condition_config' => $validated['condition_config'] ?? $rule->condition_config,
                'revision' => $rule->revision + 1,
            ]);

            if (isset($validated['actions'])) {
                $rule->actions()->delete();
                foreach ($validated['actions'] as $action) {
                    AutomationAction::create([
                        'automation_rule_id' => $rule->id,
                        'position' => $action['position'],
                        'action_type' => $action['action_type'],
                        'config' => $action['config'],
                        'active' => true,
                    ]);
                }
            }

            return response()->json($rule->fresh()->load('actions'));
        });
    }

    public function activate(Request $request, AutomationRule $rule): JsonResponse
    {
        if ($rule->active) {
            return response()->json(['message' => 'La regla ya está activa'], 409);
        }

        // Initialize cursor for audit_action triggers
        if ($rule->trigger_type === 'audit_action') {
            $maxId = AuditEvent::max('id') ?? 0;
            AutomationRuleCursor::updateOrCreate(
                ['automation_rule_id' => $rule->id, 'source' => 'audit_events'],
                ['last_source_id' => $maxId]
            );
        }

        $rule->activate();

        return response()->json($rule->fresh());
    }

    public function deactivate(Request $request, AutomationRule $rule): JsonResponse
    {
        if (! $rule->active) {
            return response()->json(['message' => 'La regla ya está inactiva'], 409);
        }

        $rule->deactivate();

        return response()->json($rule->fresh());
    }

    public function runs(Request $request, AutomationRule $rule): JsonResponse
    {
        $runs = $rule->runs()
            ->with(['actionRuns.action'])
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return response()->json($runs);
    }

    public function validatePreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trigger_type' => ['required', Rule::in([
                'audit_action', 'schedule', 'task_due',
                'document_request_due', 'receivable_overdue',
                'support_sla_breach',
            ])],
            'trigger_config' => 'required|array',
            'condition_config' => 'nullable|array',
            'actions' => 'required|array|min:1',
        ]);

        // Validate trigger config based on type
        $errors = $this->validateTriggerConfig($validated['trigger_type'], $validated['trigger_config']);
        if (! empty($errors)) {
            return response()->json(['message' => 'Configuración de disparador inválida', 'errors' => $errors], 422);
        }

        // Validate condition config
        $errors = $this->validateConditionConfig($validated['trigger_type'], $validated['condition_config'] ?? []);
        if (! empty($errors)) {
            return response()->json(['message' => 'Configuración de condiciones inválida', 'errors' => $errors], 422);
        }

        // Validate actions
        foreach ($validated['actions'] as $index => $action) {
            $errors = $this->validateActionConfig($action['action_type'] ?? '', $action['config'] ?? []);
            if (! empty($errors)) {
                return response()->json([
                    'message' => "Acción {$index} inválida",
                    'errors' => $errors,
                ], 422);
            }
        }

        return response()->json(['valid' => true]);
    }

    private function validateTriggerConfig(string $type, array $config): array
    {
        $errors = [];

        switch ($type) {
            case 'schedule':
                if (! in_array($config['frequency'] ?? '', ['daily', 'weekly', 'monthly'])) {
                    $errors[] = 'frequency debe ser daily, weekly o monthly';
                }
                if (! isset($config['time']) || ! preg_match('/^\d{2}:\d{2}$/', $config['time'])) {
                    $errors[] = 'time debe tener formato HH:MM';
                }
                if (($config['frequency'] ?? '') === 'weekly' && (! isset($config['day_of_week']) || $config['day_of_week'] < 0 || $config['day_of_week'] > 6)) {
                    $errors[] = 'day_of_week debe ser 0-6 para weekly';
                }
                if (($config['frequency'] ?? '') === 'monthly' && (! isset($config['day_of_month']) || $config['day_of_month'] < 1 || $config['day_of_month'] > 31)) {
                    $errors[] = 'day_of_month debe ser 1-31 para monthly';
                }
                break;

            case 'audit_action':
                if (empty($config['action_name'])) {
                    $errors[] = 'action_name es requerido';
                }
                break;

            case 'support_sla_breach':
                if (! in_array($config['metric'] ?? '', ['first_response', 'next_response', 'resolution'])) {
                    $errors[] = 'metric inválido';
                }
                if (! in_array($config['level'] ?? '', ['warning', 'breach'])) {
                    $errors[] = 'level debe ser warning o breach';
                }
                break;
        }

        return $errors;
    }

    private function validateConditionConfig(string $type, array $config): array
    {
        // Conditions are optional and validated based on trigger type
        return [];
    }

    private function validateActionConfig(string $type, array $config): array
    {
        $errors = [];

        switch ($type) {
            case 'internal_notification':
                if (empty($config['user_ids']) || ! is_array($config['user_ids'])) {
                    $errors[] = 'user_ids es requerido y debe ser array';
                }
                if (empty($config['title'])) {
                    $errors[] = 'title es requerido';
                }
                if (empty($config['message'])) {
                    $errors[] = 'message es requerido';
                }
                break;

            case 'create_task':
                if (empty($config['title'])) {
                    $errors[] = 'title es requerido';
                }
                if (empty($config['assignee_user_id'])) {
                    $errors[] = 'assignee_user_id es requerido';
                }
                if (empty($config['due_on']) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $config['due_on'])) {
                    $errors[] = 'due_on es requerido y debe tener formato YYYY-MM-DD';
                }
                if (! in_array($config['priority'] ?? 'normal', ['low', 'normal', 'high', 'urgent'])) {
                    $errors[] = 'priority inválido';
                }
                break;

            case 'send_email':
                if (empty($config['recipient_type'])) {
                    $errors[] = 'recipient_type es requerido';
                }
                if ($config['recipient_type'] === 'specific_user' && empty($config['specific_user_id'])) {
                    $errors[] = 'specific_user_id es requerido para recipient_type=specific_user';
                }
                if (empty($config['subject'])) {
                    $errors[] = 'subject es requerido';
                }
                if (empty($config['body'])) {
                    $errors[] = 'body es requerido';
                }
                break;

            case 'send_telegram':
                if (empty($config['endpoint_id'])) {
                    $errors[] = 'endpoint_id es requerido';
                }
                if (empty($config['message'])) {
                    $errors[] = 'message es requerido';
                }
                break;

            case 'assign_support_queue':
                if (empty($config['queue_id'])) {
                    $errors[] = 'queue_id es requerido';
                }
                break;

            case 'assign_support_user':
                if (empty($config['user_id'])) {
                    $errors[] = 'user_id es requerido';
                }
                break;

            case 'set_support_priority':
                if (! in_array($config['priority'] ?? '', ['normal', 'high', 'urgent'])) {
                    $errors[] = 'priority debe ser normal, high o urgent';
                }
                break;
        }

        return $errors;
    }
}
