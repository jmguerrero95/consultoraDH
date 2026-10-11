<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Domain\Operations\TaskStatus;
use App\Models\OperationalTask;
use App\Models\User;

/**
 * Operational tasks, read through A05's own rules.
 *
 * The status filter is restricted to the statuses the domain defines, and reading
 * requires the same permission the task screen requires. A portal account is
 * refused outright: it has no task screen to be consistent with.
 */
final class OperationalTasksTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'operational_tasks';
    }

    public function description(): string
    {
        return 'Lista tareas operativas con su estado, responsable y fecha de vencimiento.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'description' => 'Filtrar por estado.',
                    'enum' => array_column(TaskStatus::options(), 'value'),
                ],
                'assigned_to' => ['type' => 'integer', 'description' => 'Identificador del responsable.'],
                'client_id' => ['type' => 'integer', 'description' => 'Identificador del cliente.'],
                'limit' => ['type' => 'integer', 'description' => 'Máximo de resultados (1 a 25).'],
            ],
            'required' => [],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function validate(array $arguments): array
    {
        $limit = $arguments['limit'] ?? 10;

        if (! is_int($limit) && ! (is_string($limit) && ctype_digit($limit))) {
            throw new AiToolRejected("El argumento 'limit' debe ser un numero.");
        }

        return [
            'status' => $this->status($arguments),
            'assigned_to' => $this->optionalId($arguments, 'assigned_to'),
            'client_id' => $this->optionalId($arguments, 'client_id'),
            'limit' => max(1, min(25, (int) $limit)),
        ];
    }

    public function describe(array $arguments): string
    {
        return 'Listar tareas operativas'.($arguments['status'] === null ? '' : ' en estado '.$arguments['status']).'.';
    }

    public function execute(array $arguments, User $user): array
    {
        $this->denyPortalAccounts($user);
        $this->authorize($user, 'tasks.view');

        $query = OperationalTask::query()->with('assignee');

        if ($arguments['status'] !== null) {
            $query->where('status', $arguments['status']);
        }

        if ($arguments['assigned_to'] !== null) {
            $query->where('assigned_to', $arguments['assigned_to']);
        }

        if ($arguments['client_id'] !== null) {
            $query->where('client_id', $arguments['client_id']);
        }

        $tasks = $query->orderByDesc('id')->limit($arguments['limit'])->get();

        return [
            'found' => $tasks->count(),
            'tasks' => $tasks->map(fn (OperationalTask $task): array => [
                'task_id' => $task->id,
                'title' => $task->title,
                'status' => $task->status->value,
                'priority' => $task->priority->value,
                'assigned_to' => $task->assignee?->name,
                'client_id' => $task->client_id,
                'due_on' => $task->due_on?->toDateString(),
            ])->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedStatuses(): array
    {
        return array_column(TaskStatus::options(), 'value');
    }

    private function status(array $arguments): ?string
    {
        $value = $arguments['status'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return $this->requireOneOf($arguments, 'status', $this->allowedStatuses());
    }
}
