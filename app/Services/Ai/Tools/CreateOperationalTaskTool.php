<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Domain\Operations\OperationNotApplicable;
use App\Domain\Operations\TaskAssignee;
use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskStatus;
use App\Models\OperationalTask;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Proposes creating an operational task.
 *
 * ## This never creates anything
 *
 * `execute()` is reached only from a confirmed proposal. The assistant calling
 * this tool produces a proposal a person approves, which is why the class is a
 * write tool and why the registry refuses to run it as a read.
 *
 * ## A05's contract, not a new one
 *
 * The same `TaskAssignee::assertAssignable` rule the task screen applies is
 * applied here, so the assistant cannot create a task the screen would refuse,
 * and cannot assign one to a portal account.
 */
final class CreateOperationalTaskTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'create_operational_task';
    }

    public function description(): string
    {
        return 'Propone crear una tarea operativa para un colega. Presenta la propuesta a la persona para que la confirme; no crea nada por si sola.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Titulo de la tarea.'],
                'description' => ['type' => 'string', 'description' => 'Descripcion de la tarea.'],
                'assigned_to' => ['type' => 'integer', 'description' => 'Identificador del colega responsable.'],
                'priority' => [
                    'type' => 'string',
                    'description' => 'Prioridad de la tarea.',
                    'enum' => array_column(TaskPriority::options(), 'value'),
                ],
                'due_on' => ['type' => 'string', 'description' => 'Fecha de vencimiento YYYY-MM-DD.'],
                'client_id' => ['type' => 'integer', 'description' => 'Cliente al que se refiere.'],
            ],
            'required' => ['title', 'assigned_to'],
        ];
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function validate(array $arguments): array
    {
        $priority = $arguments['priority'] ?? TaskPriority::Normal->value;

        return [
            'title' => $this->requireString($arguments, 'title', 200),
            'description' => $this->optionalString($arguments, 'description', 5000),
            'assigned_to' => $this->requireId($arguments, 'assigned_to'),
            'priority' => is_string($priority)
                ? $this->requireOneOf(['priority' => $priority], 'priority', array_column(TaskPriority::options(), 'value'))
                : $priority,
            'due_on' => $this->optionalDate($arguments, 'due_on'),
            'client_id' => $this->optionalId($arguments, 'client_id'),
        ];
    }

    public function describe(array $arguments): string
    {
        $assignee = User::find($arguments['assigned_to']);

        return sprintf(
            'Crear la tarea «%s»%s, con prioridad %s%s.',
            $arguments['title'],
            $assignee === null ? '' : ' y asignarla a '.$assignee->name,
            $arguments['priority'],
            $arguments['due_on'] === null ? '' : ' y vencimiento el '.$arguments['due_on']
        );
    }

    public function execute(array $arguments, User $user): array
    {
        $this->denyPortalAccounts($user);
        $this->authorize($user, 'tasks.create');

        $assignee = User::find($arguments['assigned_to']);

        if ($assignee === null) {
            throw new AiToolRejected('La cuenta asignada no existe.');
        }

        try {
            // The same rule the task screen applies, including the refusal to
            // assign an internal task to a portal account.
            TaskAssignee::assertAssignable($assignee);
        } catch (ModelNotFoundException|OperationNotApplicable $e) {
            throw new AiToolRejected($e instanceof OperationNotApplicable ? $e->getMessage() : 'La cuenta asignada no existe.');
        }

        if ($arguments['client_id'] !== null) {
            $this->authorize($user, 'clients.view');
        }

        $task = OperationalTask::query()->create([
            'title' => $arguments['title'],
            'description' => $arguments['description'] ?? '',
            'assigned_to' => $assignee->id,
            'priority' => $arguments['priority'],
            'status' => TaskStatus::Pending->value,
            'due_on' => $arguments['due_on'],
            'client_id' => $arguments['client_id'],
            'created_by' => $user->id,
        ]);

        return [
            'created' => true,
            'task_id' => $task->id,
            'title' => $task->title,
            'assigned_to' => $assignee->name,
            'due_on' => $task->due_on?->toDateString(),
        ];
    }
}
