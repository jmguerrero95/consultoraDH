<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SupportConversation;
use App\Models\SupportInboundEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupportInboundEmailController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SupportInboundEmail::query()
            ->with(['linkedConversation.client', 'linker', 'discarder'])
            ->where('status', 'quarantined')
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('from_address', 'like', '%'.$request->input('search').'%')
                    ->orWhere('to_address', 'like', '%'.$request->input('search').'%')
                    ->orWhere('subject', 'like', '%'.$request->input('search').'%');
            });
        }

        $emails = $query->paginate($request->integer('per_page', 25));

        return response()->json($emails);
    }

    public function link(Request $request, SupportInboundEmail $email): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'subject' => 'required|string|max:255',
            'queue_id' => 'nullable|integer|exists:support_queues,id',
        ]);

        if (! $email->isQuarantined()) {
            return response()->json(['message' => 'Este correo ya fue procesado'], 409);
        }

        $client = Client::find($validated['client_id']);

        return DB::transaction(function () use ($email, $client, $validated, $request) {
            $email->lockForUpdate();

            // Create or find existing conversation
            $conversation = SupportConversation::create([
                'client_id' => $client->id,
                'queue_id' => $validated['queue_id'],
                'subject' => $validated['subject'],
                'status' => 'waiting_staff',
                'priority' => 'normal',
                'origin_channel' => 'email',
                'created_by_user_id' => $request->user()->id,
            ]);

            // Create the message from the inbound email
            SupportMessage::create([
                'conversation_id' => $conversation->id,
                'author_user_id' => null,
                'sender_kind' => 'external',
                'message_kind' => 'message',
                'channel' => 'email',
                'body_text' => $email->body_text,
                'client_visible' => true,
                'external_message_id' => $email->external_message_id,
                'ingress_fingerprint' => $email->ingress_fingerprint,
                'email_from' => $email->from_address,
                'email_to' => $email->to_address,
            ]);

            $email->status = 'linked';
            $email->linked_conversation_id = $conversation->id;
            $email->linked_by = $request->user()->id;
            $email->linked_at = now();
            $email->save();

            return response()->json($conversation->load('client', 'queue'));
        });
    }

    public function discard(Request $request, SupportInboundEmail $email): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        if (! $email->isQuarantined()) {
            return response()->json(['message' => 'Este correo ya fue procesado'], 409);
        }

        $email->update([
            'status' => 'discarded',
            'reason' => $validated['reason'],
            'discarded_by' => $request->user()->id,
            'discarded_at' => now(),
        ]);

        return response()->json($email);
    }
}
