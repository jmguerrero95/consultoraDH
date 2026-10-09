<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportQueue;
use App\Models\SupportQueueMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupportQueueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $queues = SupportQueue::query()
            ->where('active', true)
            ->with(['members.user', 'fallbackUser', 'escalationUser', 'creator'])
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();

        return response()->json($queues);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:support_queues,slug',
            'active' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
            'fallback_user_id' => 'nullable|integer|exists:users,id',
            'escalation_user_id' => 'nullable|integer|exists:users,id',
            'first_response_minutes' => 'nullable|integer|min:1',
            'next_response_minutes' => 'nullable|integer|min:1',
            'resolution_minutes' => 'nullable|integer|min:1',
            'warning_minutes_before' => 'nullable|integer|min:0',
            'fallback_email_delay_minutes' => 'nullable|integer|min:1',
            'telegram_escalation_enabled' => 'sometimes|boolean',
        ]);

        // Validate fallback/escalation users are active staff
        if ($validated['fallback_user_id'] ?? null) {
            $fallback = User::find($validated['fallback_user_id']);
            if ($fallback->account_type !== 'staff' || ! $fallback->status->is('active')) {
                return response()->json(['message' => 'El usuario de respaldo debe ser staff activo'], 422);
            }
        }

        if ($validated['escalation_user_id'] ?? null) {
            $escalation = User::find($validated['escalation_user_id']);
            if ($escalation->account_type !== 'staff' || ! $escalation->status->is('active')) {
                return response()->json(['message' => 'El usuario de escalación debe ser staff activo'], 422);
            }
        }

        // Handle default queue uniqueness
        if ($validated['is_default'] ?? false) {
            SupportQueue::where('is_default', true)->where('active', true)->update(['is_default' => false]);
        }

        return DB::transaction(function () use ($validated, $request) {
            $queue = SupportQueue::create([
                ...$validated,
                'created_by' => $request->user()->id,
            ]);

            return response()->json($queue->load(['members.user', 'fallbackUser', 'escalationUser', 'creator']), 201);
        });
    }

    public function update(Request $request, SupportQueue $queue): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|required|string|max:255|unique:support_queues,slug,'.$queue->id,
            'active' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
            'fallback_user_id' => 'nullable|integer|exists:users,id',
            'escalation_user_id' => 'nullable|integer|exists:users,id',
            'first_response_minutes' => 'nullable|integer|min:1',
            'next_response_minutes' => 'nullable|integer|min:1',
            'resolution_minutes' => 'nullable|integer|min:1',
            'warning_minutes_before' => 'nullable|integer|min:0',
            'fallback_email_delay_minutes' => 'nullable|integer|min:1',
            'telegram_escalation_enabled' => 'sometimes|boolean',
        ]);

        // Validate fallback/escalation users are active staff
        if ($validated['fallback_user_id'] ?? null) {
            $fallback = User::find($validated['fallback_user_id']);
            if ($fallback->account_type !== 'staff' || ! $fallback->status->is('active')) {
                return response()->json(['message' => 'El usuario de respaldo debe ser staff activo'], 422);
            }
        }

        if ($validated['escalation_user_id'] ?? null) {
            $escalation = User::find($validated['escalation_user_id']);
            if ($escalation->account_type !== 'staff' || ! $escalation->status->is('active')) {
                return response()->json(['message' => 'El usuario de escalación debe ser staff activo'], 422);
            }
        }

        // Handle default queue uniqueness
        if (($validated['is_default'] ?? false) && ! $queue->is_default) {
            SupportQueue::where('is_default', true)->where('active', true)->where('id', '!=', $queue->id)->update(['is_default' => false]);
        }

        $queue->update($validated);

        return response()->json($queue->load(['members.user', 'fallbackUser', 'escalationUser', 'creator']));
    }

    public function addMember(Request $request, SupportQueue $queue): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $user = User::find($validated['user_id']);

        if ($user->account_type !== 'staff' || ! $user->status->is('active')) {
            return response()->json(['message' => 'Solo se pueden agregar usuarios staff activos a una cola'], 422);
        }

        $member = SupportQueueMember::firstOrCreate(
            ['queue_id' => $queue->id, 'user_id' => $user->id],
            ['created_by' => $request->user()->id]
        );

        return response()->json($member->load('user'), 201);
    }

    public function removeMember(Request $request, SupportQueue $queue, User $user): JsonResponse
    {
        $member = SupportQueueMember::where('queue_id', $queue->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $member->delete();

        return response()->json(['message' => 'Miembro removido']);
    }
}
