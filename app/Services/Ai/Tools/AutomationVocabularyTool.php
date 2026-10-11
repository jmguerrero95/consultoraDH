<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Models\User;

/**
 * Returns the official A06 automation vocabulary.
 *
 * The AI must build supported A06 rules using this vocabulary.
 * Do not let the LLM invent trigger/action names.
 */
final class AutomationVocabularyTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'automation_vocabulary';
    }

    public function description(): string
    {
        return 'Devuelve el vocabulario oficial de automatizaciones A06: triggers, condiciones y acciones soportadas. El asistente debe usar esto para construir reglas válidas.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function validate(array $arguments): array
    {
        return [];
    }

    public function describe(array $arguments): string
    {
        return 'Obtener vocabulario de automatizaciones A06';
    }

    public function execute(array $arguments, User $user): array
    {
        $this->authorize($user, 'automations.view');

        return [
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
        ];
    }
}