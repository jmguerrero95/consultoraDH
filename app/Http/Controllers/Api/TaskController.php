<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Operations\Actions\CancelTask;
use App\Domain\Operations\Actions\CompleteTask;
use App\Domain\Operations\Actions\ReassignTask;
use App\Domain\Operations\OperationNotApplicable;
use App\Domain\Operations\TaskAssignee;
use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\OperationalTask;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaskController extends Controller
{
    public function __construct(
        private readonly CompleteTask $completer,
        private readonly CancelTask $canceller,
        private readonly ReassignTask $reassigner,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = OperationalTask::query()->with(['assignee', 'client'])->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority')->toString());
        }
        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->integer('assigned_to'));
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }

        $tasks = $query->paginate(min($request->integer('per_page', 20), 100));

        return response()->json([
            'data' => $tasks->map(fn (OperationalTask $t): array => $this->present($t))->all(),
            'pagination' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'novelty_id' => ['nullable', 'integer', 'exists:client_novelties,id'],
            'document_request_id' => ['nullable', 'integer', 'exists:client_document_requests,id'],
            'contribution_sheet_id' => ['nullable', 'integer', 'exists:contribution_sheets,id'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
            'priority' => ['required', 'string', 'in:'.implode(',', array_column(TaskPriority::options(), 'value'))],
            'due_on' => ['nullable', 'date'],
            'reminder_at' => ['nullable', 'date'],
        ]);

        // §R1: an internal task goes to a colleague, never to a portal account.
        try {
            TaskAssignee::assertAssignable(User::query()->findOrFail($data['assigned_to']));
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'La cuenta asignada no existe.', 'code' => 'assignee_not_found'], 422);
        } catch (OperationNotApplicable $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 422);
        }

        $task = OperationalTask::query()->create($data + [
            'status' => TaskStatus::Pending->value,
            'created_by' => $request->user()->id,
        ]);

        $this->audit->record(AuditAction::TaskCreated, $request->user(), [
            'title' => $task->title,
            'assigned_to' => $task->assigned_to,
        ], null, $task);

        return response()->json($this->present($task), 201);
    }

    public function complete(OperationalTask $task, Request $request): JsonResponse
    {
        try {
            $result = $this->completer->handle($task, $request->user());
        } catch (OperationNotApplicable $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 409);
        }

        return response()->json($this->present($result));
    }

    public function cancel(OperationalTask $task, Request $request): JsonResponse
    {
        try {
            $result = $this->canceller->handle($task, $request->user());
        } catch (OperationNotApplicable $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 409);
        }

        return response()->json($this->present($result));
    }

    public function reassign(OperationalTask $task, Request $request): JsonResponse
    {
        $newAssigneeId = $request->integer('assigned_to');

        try {
            $newAssignee = User::query()->findOrFail($newAssigneeId);
            $result = $this->reassigner->handle($task, $newAssignee, $request->user());
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'La cuenta asignada no existe.', 'code' => 'assignee_not_found'], 422);
        } catch (OperationNotApplicable $e) {
            // A refused assignee is bad input (422); a refused state is a conflict (409).
            $esEntrada = in_array($e->reason, ['assignee_not_staff', 'assignee_not_active'], true);

            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->reason,
            ], $esEntrada ? 422 : 409);
        }

        return response()->json($this->present($result));
    }

    public function vocabulary(): JsonResponse
    {
        return response()->json([
            'priorities' => TaskPriority::options(),
            'statuses' => TaskStatus::options(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OperationalTask $task): array
    {
        return [
            'id' => (int) $task->id,
            'client_id' => $task->client_id,
            'client_name' => $task->client?->fullName(),
            'title' => $task->title,
            'description' => $task->description,
            'assigned_to' => (int) $task->assigned_to,
            'assignee_name' => $task->assignee?->name,
            'priority' => $task->priority->value,
            'priority_label' => $task->priority->label(),
            'status' => $task->status->value,
            'status_label' => $task->status->label(),
            'due_on' => $task->due_on?->format('Y-m-d'),
            'reminder_at' => $task->reminder_at?->toIso8601String(),
            'reminder_sent_at' => $task->reminder_sent_at?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'created_at' => $task->created_at->toIso8601String(),
        ];
    }
}
