<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Models\AutomationAction;
use App\Models\AutomationRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Proposes creating an automation rule using A06 vocabulary.
 *
 * The AI must build a supported A06 rule from the vocabulary, display it,
 * and require confirmation. This tool produces a proposal; it does not
 * create the rule until confirmed.
 */
final class CreateAutomationRuleTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'create_automation_rule';
    }

    public function description(): string
    {
        return 'Propone crear una regla de automatización A06. Debe usar el vocabulario oficial (automation_vocabulary). La regla se crea como propuesta pendiente de confirmación.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Nombre de la regla.'],
                'description' => ['type' => 'string', 'description' => 'Descripción opcional.'],
                'trigger_type' => [
                    'type' => 'string',
                    'description' => 'Tipo de disparador.',
                    'enum' => ['audit_action', 'schedule', 'task_due', 'document_request_due', 'receivable_overdue', 'support_sla_breach'],
                ],
                'trigger_config' => ['type' => 'object', 'description' => 'Configuración del disparador según vocabulario.'],
                'condition_config' => ['type' => 'object', 'description' => 'Configuración de condiciones (vacío para schedule).', 'nullable' => true],
                'actions' => [
                    'type' => 'array',
                    'description' => 'Lista de acciones (mínimo 1).',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'action_type' => [
                                'type' => 'string',
                                'enum' => ['internal_notification', 'create_task', 'send_email', 'send_telegram', 'assign_support_queue', 'assign_support_user', 'set_support_priority'],
                            ],
                            'config' => ['type' => 'object', 'description' => 'Configuración de la acción según vocabulario.'],
                            'position' => ['type' => 'integer', 'description' => 'Orden de ejecución (0, 1, 2...).'],
                        ],
                        'required' => ['action_type', 'config', 'position'],
                    ],
                    'minItems' => 1,
                ],
            ],
            'required' => ['name', 'trigger_type', 'trigger_config', 'actions'],
        ];
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function validate(array $arguments): array
    {
        $name = $this->requireString($arguments, 'name', 255);
        $description = $this->optionalString($arguments, 'description', 1000);
        $triggerType = $this->requireOneOf($arguments, 'trigger_type', [
            'audit_action', 'schedule', 'task_due', 'document_request_due', 'receivable_overdue', 'support_sla_breach',
        ]);
        $triggerConfig = $arguments['trigger_config'] ?? [];
        $conditionConfig = $arguments['condition_config'] ?? [];
        $actions = $arguments['actions'] ?? [];

        if (! is_array($triggerConfig)) {
            throw new AiToolRejected("'trigger_config' debe ser un objeto.");
        }

        if (! is_array($conditionConfig)) {
            throw new AiToolRejected("'condition_config' debe ser un objeto.");
        }

        if (! is_array($actions) || count($actions) === 0) {
            throw new AiToolRejected("'actions' debe ser un array con al menos una acción.");
        }

        foreach ($actions as $index => $action) {
            if (! is_array($action)) {
                throw new AiToolRejected("Acción {$index} debe ser un objeto.");
            }
            $actionType = $action['action_type'] ?? null;
            if (! in_array($actionType, [
                'internal_notification', 'create_task', 'send_email', 'send_telegram',
                'assign_support_queue', 'assign_support_user', 'set_support_priority',
            ], true)) {
                throw new AiToolRejected("Acción {$index}: tipo de acción inválido.");
            }
            if (! isset($action['config']) || ! is_array($action['config'])) {
                throw new AiToolRejected("Acción {$index}: 'config' es requerido y debe ser un objeto.");
            }
            if (! isset($action['position']) || ! is_int($action['position']) || $action['position'] < 0) {
                throw new AiToolRejected("Acción {$index}: 'position' es requerido y debe ser entero >= 0.");
            }
        }

        // Validate trigger config
        $this->validateTriggerConfig($triggerType, $triggerConfig);

        // Schedule triggers must not have conditions
        if ($triggerType === 'schedule' && ! empty($conditionConfig)) {
            throw new AiToolRejected('Los disparadores programados no admiten condiciones dinámicas.');
        }

        // Validate actions config
        foreach ($actions as $index => $action) {
            $this->validateActionConfig($action['action_type'], $action['config'], $index);
        }

        return [
            'name' => $name,
            'description' => $description,
            'trigger_type' => $triggerType,
            'trigger_config' => $triggerConfig,
            'condition_config' => $conditionConfig,
            'actions' => $actions,
        ];
    }

    public function describe(array $arguments): string
    {
        return sprintf(
            'Crear regla de automatización: %s (trigger: %s, %d acción%s)',
            $arguments['name'],
            $arguments['trigger_type'],
            count($arguments['actions']),
            count($arguments['actions']) === 1 ? '' : 'es'
        );
    }

    public function execute(array $arguments, User $user): array
    {
        $this->denyPortalAccounts($user);
        $this->authorize($user, 'automations.manage');

        return DB::transaction(function () use ($arguments, $user) {
            $rule = AutomationRule::create([
                'name' => $arguments['name'],
                'description' => $arguments['description'],
                'trigger_type' => $arguments['trigger_type'],
                'trigger_config' => $arguments['trigger_config'],
                'condition_config' => $arguments['condition_config'],
                'owner_user_id' => $user->id,
                'active' => false, // Created as inactive, must be activated separately
            ]);

            foreach ($arguments['actions'] as $action) {
                AutomationAction::create([
                    'automation_rule_id' => $rule->id,
                    'position' => $action['position'],
                    'action_type' => $action['action_type'],
                    'config' => $action['config'],
                    'active' => true,
                ]);
            }

            return [
                'created' => true,
                'rule_id' => $rule->id,
                'name' => $rule->name,
                'trigger_type' => $rule->trigger_type,
                'active' => false,
                'message' => 'Regla creada (inactiva). Use activate_automation_rule para activarla.',
            ];
        });
    }

    private function validateTriggerConfig(string $type, array $config): void
    {
        switch ($type) {
            case 'schedule':
                if (! in_array($config['frequency'] ?? '', ['daily', 'weekly', 'monthly'])) {
                    throw new AiToolRejected('frequency debe ser daily, weekly o monthly');
                }
                if (! isset($config['time']) || ! preg_match('/^\d{2}:\d{2}$/', $config['time'])) {
                    throw new AiToolRejected('time debe tener formato HH:MM');
                }
                if (($config['frequency'] ?? '') === 'weekly' && (! isset($config['day_of_week']) || $config['day_of_week'] < 0 || $config['day_of_week'] > 6)) {
                    throw new AiToolRejected('day_of_week debe ser 0-6 para weekly');
                }
                if (($config['frequency'] ?? '') === 'monthly' && (! isset($config['day_of_month']) || $config['day_of_month'] < 1 || $config['day_of_month'] > 31)) {
                    throw new AiToolRejected('day_of_month debe ser 1-31 para monthly');
                }
                break;
            case 'audit_action':
                if (empty($config['action_name'])) {
                    throw new AiToolRejected('action_name es requerido para audit_action');
                }
                break;
            case 'support_sla_breach':
                if (! in_array($config['metric'] ?? '', ['first_response', 'next_response', 'resolution'])) {
                    throw new AiToolRejected('metric inválido para support_sla_breach');
                }
                if (! in_array($config['level'] ?? '', ['warning', 'breach'])) {
                    throw new AiToolRejected('level debe ser warning o breach');
                }
                break;
        }
    }

    private function validateActionConfig(string $type, array $config, int $index): void
    {
        switch ($type) {
            case 'internal_notification':
                if (empty($config['user_ids']) || ! is_array($config['user_ids'])) {
                    throw new AiToolRejected("Acción {$index}: user_ids es requerido y debe ser array");
                }
                if (empty($config['title'])) {
                    throw new AiToolRejected("Acción {$index}: title es requerido");
                }
                if (empty($config['message'])) {
                    throw new AiToolRejected("Acción {$index}: message es requerido");
                }
                break;
            case 'create_task':
                if (empty($config['title'])) {
                    throw new AiToolRejected("Acción {$index}: title es requerido");
                }
                if (empty($config['assignee_user_id'])) {
                    throw new AiToolRejected("Acción {$index}: assignee_user_id es requerido");
                }
                if (empty($config['due_on']) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $config['due_on'])) {
                    throw new AiToolRejected("Acción {$index}: due_on es requerido y debe tener formato YYYY-MM-DD");
                }
                if (! in_array($config['priority'] ?? 'normal', ['low', 'normal', 'high', 'urgent'])) {
                    throw new AiToolRejected("Acción {$index}: priority inválido");
                }
                break;
            case 'send_email':
                if (empty($config['recipient_type'])) {
                    throw new AiToolRejected("Acción {$index}: recipient_type es requerido");
                }
                if ($config['recipient_type'] === 'specific_user' && empty($config['specific_user_id'])) {
                    throw new AiToolRejected("Acción {$index}: specific_user_id es requerido para recipient_type=specific_user");
                }
                if (empty($config['subject'])) {
                    throw new AiToolRejected("Acción {$index}: subject es requerido");
                }
                if (empty($config['body'])) {
                    throw new AiToolRejected("Acción {$index}: body es requerido");
                }
                break;
            case 'send_telegram':
                if (empty($config['endpoint_id'])) {
                    throw new AiToolRejected("Acción {$index}: endpoint_id es requerido");
                }
                if (empty($config['message'])) {
                    throw new AiToolRejected("Acción {$index}: message es requerido");
                }
                break;
            case 'assign_support_queue':
                if (empty($config['queue_id'])) {
                    throw new AiToolRejected("Acción {$index}: queue_id es requerido");
                }
                break;
            case 'assign_support_user':
                if (empty($config['user_id'])) {
                    throw new AiToolRejected("Acción {$index}: user_id es requerido");
                }
                break;
            case 'set_support_priority':
                if (! in_array($config['priority'] ?? '', ['normal', 'high', 'urgent'])) {
                    throw new AiToolRejected("Acción {$index}: priority debe ser normal, high o urgent");
                }
                break;
        }
    }
}
