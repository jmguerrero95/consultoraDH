<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Models\User;

/**
 * The people a task may be assigned to, by name and id.
 *
 * ## The defect this closes
 *
 * Asked to "crear una tarea para María", the assistant asked the person for
 * María's *database id* — a number only a developer knows. The assignment rule
 * (`TaskAssignee`) already requires the responsible party to be active staff;
 * what was missing was a way to get from a name to the id the write needs.
 *
 * ## The same list the form already offers
 *
 * This returns exactly what the task screen's `vocabulary` endpoint returns for
 * the assignee picker: active staff, by name and id, under `tasks.view`. That
 * permission already discloses who is responsible for work through every task
 * row, so naming the candidates adds no authority and exposes no client
 * account. The write itself still checks `tasks.create`, and the assignment is
 * still `TaskAssignee::assertAssignable`, so the assistant cannot assign work
 * the screen would refuse.
 */
final class TeamDirectoryTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'team_directory';
    }

    public function description(): string
    {
        return 'Lista las personas del equipo a las que se puede asignar una tarea operativa (solo personal activo), '
            .'con su nombre y su id. Úsala para resolver por nombre el responsable de una tarea antes de proponerla '
            .'con create_operational_task. Nunca le pidas al usuario un id de base de datos: búscalo aquí.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Parte del nombre a buscar. Si se omite, se listan todos los responsables disponibles.',
                ],
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
        return [
            'query' => isset($arguments['query']) ? trim((string) $arguments['query']) : '',
        ];
    }

    public function describe(array $arguments): string
    {
        return 'Listar responsables disponibles'.($arguments['query'] !== '' ? " que coincidan con «{$arguments['query']}»" : '');
    }

    public function execute(array $arguments, User $user): array
    {
        $this->authorize($user, 'tasks.view');

        $query = User::query()
            ->where('account_type', 'staff')
            // The model's scope, not a raw `status` column: a deactivated person
            // cannot sign in to do the work, so offering them would be offering
            // a task nobody can pick up.
            ->active()
            ->orderBy('name');

        if ($arguments['query'] !== '') {
            $query->where('name', 'like', '%'.$arguments['query'].'%');
        }

        $people = $query->limit(50)
            ->get(['id', 'name'])
            ->map(static fn (User $person): array => [
                'id' => (int) $person->id,
                'name' => $person->name,
            ])
            ->values()
            ->all();

        return [
            'count' => count($people),
            'people' => $people,
            'note' => $people === []
                ? 'No hay personal activo que coincida. Confirma el nombre con el usuario; no inventes un responsable.'
                : 'Usa el id de esta lista para assigned_to. No le pidas un id al usuario.',
        ];
    }
}
