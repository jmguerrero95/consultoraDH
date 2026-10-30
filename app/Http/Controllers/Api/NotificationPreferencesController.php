<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Users\UserStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The notification choices of the authenticated employee.
 *
 * ## The defaults preserve old behaviour
 *
 * Internal and Push notifications were already being sent; an employee who
 * never opens this screen keeps receiving them. Telegram task reminders and
 * report notices are opt-in, because enrolling somebody in a new external
 * channel without asking is not a default this application takes.
 *
 * ## The server owns the shape
 *
 * Only the four known keys are accepted, and each is a boolean. A request
 * cannot invent a channel, and a value that is not a decision cannot widen what
 * is sent.
 */
final class NotificationPreferencesController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null || $user->status !== UserStatus::Active) {
            return response()->json(['message' => 'Solo una cuenta activa puede cambiar sus preferencias.'], 403);
        }

        $validated = $request->validate([
            'in_app' => 'sometimes|boolean',
            'push' => 'sometimes|boolean',
            'telegram_task_reminders' => 'sometimes|boolean',
            'telegram_report_ready' => 'sometimes|boolean',
        ]);

        if ($validated === []) {
            return response()->json(['message' => 'No hay preferencias que guardar.'], 422);
        }

        $current = $user->notificationPreferenceMap();
        $next = [...$current, ...$validated];

        $user->forceFill(['notification_preferences' => $next])->save();

        $this->audit->record(AuditAction::NotificationPreferencesUpdated, $user, [
            'channels' => array_keys($validated),
            'self' => true,
        ]);

        return response()->json([
            'message' => 'Preferencias guardadas.',
            'preferences' => $user->notificationPreferenceMap(),
            'telegram_linked' => $user->telegramEndpoints()->where('enabled', true)->exists(),
            'push_devices' => $user->pushSubscriptions()->whereNull('failed_at')->count(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $user = $request->user();

        return [
            'preferences' => $user->notificationPreferenceMap(),
            'available' => [
                'in_app' => [
                    'label' => 'Avisos internos',
                    'description' => 'El aviso aparece en la campana de la aplicación.',
                    'default' => true,
                ],
                'push' => [
                    'label' => 'Notificaciones Push',
                    'description' => 'Un recordatorio llega al navegador de los dispositivos activados.',
                    'default' => true,
                ],
                'telegram_task_reminders' => [
                    'label' => 'Recordatorios de tareas por Telegram',
                    'description' => 'El aviso inicial de una tarea se copia a su chat personal de Telegram, si está vinculado.',
                    'default' => false,
                ],
                'telegram_report_ready' => [
                    'label' => 'Reportes disponibles por Telegram',
                    'description' => 'Cuando un reporte programado suyo termina, se avisa a su chat personal de Telegram.',
                    'default' => false,
                ],
            ],
            'telegram_linked' => $user->telegramEndpoints()->where('enabled', true)->exists(),
            'push_devices' => $user->pushSubscriptions()->whereNull('failed_at')->count(),
        ];
    }
}
