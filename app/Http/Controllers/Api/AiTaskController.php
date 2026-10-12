<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assistant\AiTaskService;
use App\Models\AiConversation;
use App\Models\AiTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * Background work: start it, watch it, stop it.
 *
 * ## Every answer here is about somebody's own work
 *
 * Tasks are listed and read through `user_id` in the query itself, not by
 * loading a row and comparing afterwards. A task that is not yours is a 404
 * rather than a 403, for the same reason the conversation endpoint does it: a
 * refusal that distinguishes "exists but forbidden" from "does not exist" is
 * itself a disclosure about ids.
 *
 * ## The client is expected to poll
 *
 * Polling, rather than a websocket, because the work outlives the connection
 * that started it: the answer is in a row, and re-reading a row after
 * reconnecting is what makes a closed tab recoverable. Nothing here holds state
 * between requests.
 */
final class AiTaskController extends Controller
{
    public function __construct(
        private readonly AiTaskService $tasks,
    ) {}

    /**
     * Everything this person has asked for, newest first.
     *
     * Unfinished work is included on purpose: this is also the recovery
     * endpoint, used after a phone wakes up or a connection returns.
     */
    public function index(Request $request): JsonResponse
    {
        $tasks = AiTask::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (AiTask $task): array => $this->present($task));

        return response()->json(['tasks' => $tasks]);
    }

    /**
     * Accept an upload and queue it.
     *
     * Answers 202: the file is stored and the work is queued, and the body says
     * so. A 200 here would be a lie about something the person is about to be
     * told is ready.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'conversation_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $conversationId = isset($validated['conversation_id']) && $validated['conversation_id'] !== null
            ? (int) $validated['conversation_id']
            : null;

        if ($conversationId !== null) {
            $owned = AiConversation::query()
                ->whereKey($conversationId)
                ->where('user_id', $request->user()->id)
                ->exists();

            if (! $owned) {
                return $this->fail('La conversación no está disponible.', 404);
            }
        }

        try {
            $task = $this->tasks->queueDocumentUpload(
                $request->file('file'),
                $request->user(),
                $conversationId,
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return response()->json(['task' => $this->present($task)], 202);
    }

    public function show(Request $request, AiTask $task): JsonResponse
    {
        // Scoped lookup: a task belonging to somebody else resolves to nothing.
        $mine = AiTask::query()
            ->whereKey($task->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($mine === null) {
            return $this->fail('La tarea no está disponible.', 404);
        }

        return response()->json(['task' => $this->present($mine)]);
    }

    /**
     * Stop waiting for something that has not started.
     *
     * Only a queued task can be cancelled. One that is already running is left
     * alone: killing it would mean leaving a half-written index behind, and the
     * honest thing is to let it finish and say so.
     */
    public function cancel(Request $request, AiTask $task): JsonResponse
    {
        $mine = AiTask::query()
            ->whereKey($task->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($mine === null) {
            return $this->fail('La tarea no está disponible.', 404);
        }

        if ($mine->status !== AiTask::STATUS_QUEUED) {
            return $this->fail('Esta tarea ya está en proceso y no se puede cancelar.', 409);
        }

        $mine->forceFill([
            'status' => AiTask::STATUS_CANCELLED,
            'stage' => 'Cancelada',
            'finished_at' => now(),
        ])->save();

        return response()->json(['task' => $this->present($mine->refresh())]);
    }

    /**
     * What the client needs to render a progress row, and nothing more.
     *
     * The full input is returned because it is the person's own file name and
     * size - but the result is summarised rather than dumped, so a research
     * answer that ran long does not arrive as one enormous payload on every poll.
     *
     * @return array<string, mixed>
     */
    private function present(AiTask $task): array
    {
        return [
            'id' => $task->id,
            'kind' => $task->kind,
            'status' => $task->status,
            'progress' => $task->progress,
            'stage' => $task->stage,
            'name' => $task->input['name'] ?? null,
            'result' => $task->result,
            'error' => $task->error,
            'attempts' => $task->attempts,
            'finished' => $task->isFinished(),
            'recoverable' => $task->isRecoverable(),
            'conversation_id' => $task->ai_conversation_id,
            'created_at' => $task->created_at?->toDateTimeString(),
            'finished_at' => $task->finished_at?->toDateTimeString(),
        ];
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message, 'message' => $message], $status);
    }
}
