<?php

declare(strict_types=1);

namespace App\Services\Telegram;

/**
 * The events a Telegram destination can actually subscribe to.
 *
 * ## Why this is a closed catalogue
 *
 * The previous screen offered four checkboxes because four strings appeared in
 * a validation rule, not because four producers existed. `urgent_unassigned`
 * cannot happen at all in this schema — every task has a non-null assignee —
 * and a checkbox that saves a preference nobody ever reads is a control that
 * lies. The unavailable key is kept in the catalogue, with the reason stated,
 * so an existing row that already carries it is not silently rewritten, but it
 * is not offered as a new subscription.
 *
 * Every available entry below names the code that produces it and the moment it
 * fires. Adding an event here means pointing at a producer in the same change.
 */
final class TelegramEventCatalog
{
    /**
     * @return array<string, array{
     *     label: string,
     *     description: string,
     *     available: bool,
     *     unavailable_reason: string|null,
     *     producer: string,
     *     trigger: string,
     *     recipients: string,
     *     channels: list<string>,
     *     default_enabled: bool,
     *     deduplication: string,
     *     route: string
     * }>
     */
    public static function all(): array
    {
        return [
            'support_sla_breach' => [
                'label' => 'Incumplimiento de SLA',
                'description' => 'Una conversación de soporte superó el tiempo de respuesta comprometido.',
                'available' => true,
                'unavailable_reason' => null,
                'producer' => 'App\\Domain\\Support\\Listeners\\EscalateSupportSla',
                'trigger' => 'Cuando un evento de SLA pasa a incumplido.',
                'recipients' => 'Destinos administrativos, y los limitados a la conversación.',
                'channels' => ['telegram'],
                'default_enabled' => false,
                'deduplication' => 'Clave por evento de SLA y destino.',
                'route' => '/soporte',
            ],
            'automation_alert' => [
                'label' => 'Alerta de automatización',
                'description' => 'Una regla de automatización terminó parcial o bloqueada.',
                'available' => true,
                'unavailable_reason' => null,
                'producer' => 'App\\Domain\\Automations\\AutomationAlertNotifier',
                'trigger' => 'Cuando una ejecución de automatización termina con acciones fallidas o bloqueadas.',
                'recipients' => 'Destinos administrativos.',
                'channels' => ['telegram'],
                'default_enabled' => false,
                'deduplication' => 'Una entrega por ejecución y destino.',
                'route' => '/automatizaciones',
            ],
            'document_overdue' => [
                'label' => 'Documento pendiente o vencido',
                'description' => 'Una solicitud de documento sigue pendiente después de su fecha límite.',
                'available' => true,
                'unavailable_reason' => null,
                'producer' => 'App\\Console\\Commands\\ScanOverdueDocumentRequests',
                'trigger' => 'Barrido programado; una vez por solicitud y día.',
                'recipients' => 'Destinos administrativos.',
                'channels' => ['telegram'],
                'default_enabled' => false,
                'deduplication' => 'Clave por solicitud, fecha límite y día del aviso.',
                'route' => '/documentos',
            ],
            'task_reminder' => [
                'label' => 'Recordatorio de tarea',
                'description' => 'El aviso inicial de una tarea llegó a su hora.',
                'available' => true,
                'unavailable_reason' => null,
                'producer' => 'App\\Domain\\Operations\\ReminderDispatcher',
                'trigger' => 'Aviso inicial del recordatorio; el segundo aviso no se replica.',
                'recipients' => 'Chat personal del empleado asignado, si lo activó.',
                'channels' => ['push', 'in_app', 'telegram'],
                'default_enabled' => false,
                'deduplication' => 'Clave por tarea, etapa y destinatario.',
                'route' => '/operacion/tareas',
            ],
            'report_available' => [
                'label' => 'Reporte disponible',
                'description' => 'Un reporte programado terminó y quedó disponible para su dueño.',
                'available' => true,
                'unavailable_reason' => null,
                'producer' => 'App\\Domain\\Reports\\ReportScheduleRunner',
                'trigger' => 'Cuando un reporte programado se genera correctamente.',
                'recipients' => 'Chat personal del dueño del reporte, si lo activó.',
                'channels' => ['in_app', 'telegram'],
                'default_enabled' => false,
                'deduplication' => 'Clave por ocurrencia del reporte y destinatario.',
                'route' => '/reportes',
            ],
            'urgent_unassigned' => [
                'label' => 'Urgente sin asignar',
                'description' => 'Reservado para una tarea urgente que todavía no tiene responsable.',
                'available' => false,
                'unavailable_reason' => 'El esquema exige un responsable en toda tarea; hoy no existe una tarea urgente sin asignar que pueda producir este aviso.',
                'producer' => 'Ninguno',
                'trigger' => 'No se dispara.',
                'recipients' => 'Destinos administrativos.',
                'channels' => ['telegram'],
                'default_enabled' => false,
                'deduplication' => 'No aplica.',
                'route' => '/operacion/tareas',
            ],
        ];
    }

    /**
     * Keys the API accepts on a destination.
     *
     * Includes the unavailable one on purpose: an existing endpoint may already
     * carry it, and refusing the whole update would force an administrator to
     * edit a value they did not intend to touch.
     *
     * @return list<string>
     */
    public static function acceptedKeys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Keys a new subscription may choose.
     *
     * @return list<string>
     */
    public static function availableKeys(): array
    {
        return array_keys(array_filter(
            self::all(),
            static fn (array $event): bool => $event['available'],
        ));
    }

    /**
     * Keys an administrative destination may choose.
     *
     * Personal-only events (`task_reminder`, `report_available`) are excluded:
     * they are delivered to the private chat of the person concerned, and a
     * shared channel cannot be that chat.
     *
     * @return list<string>
     */
    public static function adminKeys(): array
    {
        return array_values(array_filter(
            self::availableKeys(),
            static fn (string $key): bool => ! in_array($key, ['task_reminder', 'report_available'], true),
        ));
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }
}
