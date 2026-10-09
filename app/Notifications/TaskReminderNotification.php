<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\OperationalTask;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class TaskReminderNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly OperationalTask $task) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'task_reminder',
            'title' => 'Recordatorio de tarea',
            'message' => $this->task->title,
            'task_id' => (int) $this->task->id,
            'route' => '/operacion/tareas',
        ];
    }
}
