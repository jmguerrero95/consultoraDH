<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportInboxController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = SupportConversation::query()
            ->with(['client', 'queue', 'assignee', 'lastMessage', 'slaEvents'])
            ->whereHas('queue', function ($q) use ($user) {
                if ($user->hasPermissionTo('support.view_all')) {
                    return;
                }
                $q->whereHas('members', function ($mq) use ($user) {
                    $mq->where('user_id', $user->id);
                });
            });

        // Filters
        if ($request->filled('queue')) {
            $query->where('queue_id', $request->input('queue'));
        }

        if ($request->filled('assigned_to_me') && $request->boolean('assigned_to_me')) {
            $query->where('assigned_to_user_id', $user->id);
        }

        if ($request->filled('unassigned') && $request->boolean('unassigned')) {
            $query->whereNull('assigned_to_user_id');
        }

        if ($request->filled('assignee')) {
            $query->where('assigned_to_user_id', $request->input('assignee'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->filled('sla_state')) {
            // Implement SLA state filtering
        }

        if ($request->filled('unread') && $request->boolean('unread')) {
            $query->whereHas('readStates', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->where(function ($sub) {
                        $sub->whereNull('last_read_message_id')
                            ->orWhereHas('conversation.messages', function ($msg) {
                                $msg->where('id', '>', $msg->getModel()->getConnection()->raw('support_read_states.last_read_message_id'))
                                    ->where('client_visible', true);
                            });
                    });
            }, '>', 0);
        }

        if ($request->filled('client_search')) {
            $query->whereHas('client', function ($q) use ($request) {
                $q->where('document_number', 'like', '%'.$request->input('client_search').'%')
                    ->orWhere('first_names', 'like', '%'.$request->input('client_search').'%')
                    ->orWhere('last_names', 'like', '%'.$request->input('client_search').'%');
            });
        }

        $conversations = $query->orderByDesc('last_message_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($conversations);
    }
}
