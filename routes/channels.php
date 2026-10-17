<?php

use App\Models\SupportConversation;
use App\Models\SupportQueueMember;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Support Channels
|--------------------------------------------------------------------------
|
| Private channels for support conversations and presence.
| Authorization is server-side and mirrors the backend authorization rules.
|
*/

// Support conversation channel - for realtime messages and updates (client-visible)
Broadcast::channel('support.conversation.{conversationId}', function (User $user, string $conversationId) {
    // A channel name that is not a number cannot name a conversation.
    //
    // This was the 500 behind `/broadcasting/auth`: the create screen had no
    // conversation yet, so the browser asked for `support.conversation.NaN`, and
    // `find('NaN')` reached PostgreSQL as a bigint comparison and raised a query
    // exception inside the auth callback. An unparsable id is a refusal, not a
    // server error, so it is refused here before it ever reaches the database.
    if (! ctype_digit($conversationId)) {
        return false;
    }

    $conversation = SupportConversation::find((int) $conversationId);

    if (! $conversation) {
        return false;
    }

    // Client can only join their own conversation
    if ($user->account_type === 'client') {
        return $user->client_id !== null && $conversation->client_id === $user->client_id;
    }

    // Staff can join if they have support.view_all or are a member of the queue
    // or are assigned to the conversation
    if ($user->hasPermissionTo('support.view_all')) {
        return true;
    }

    // Check queue membership
    $isQueueMember = SupportQueueMember::where('queue_id', $conversation->queue_id)
        ->where('user_id', $user->id)
        ->exists();

    if ($isQueueMember) {
        return true;
    }

    // Check if assigned
    if ($conversation->assigned_to_user_id === $user->id) {
        return true;
    }

    return false;
});

// Staff-only conversation channel - for internal notes and staff-only messages
Broadcast::channel('support.staff.conversation.{conversationId}', function (User $user, string $conversationId) {
    if ($user->account_type === 'client') {
        return false;
    }

    // Same guard as the client-visible channel above: `NaN` is not an id.
    if (! ctype_digit($conversationId)) {
        return false;
    }

    $conversation = SupportConversation::find((int) $conversationId);

    if (! $conversation) {
        return false;
    }

    // Staff can join if they have support.view_all or are a member of the queue
    // or are assigned to the conversation
    if ($user->hasPermissionTo('support.view_all')) {
        return true;
    }

    // Check queue membership
    $isQueueMember = SupportQueueMember::where('queue_id', $conversation->queue_id)
        ->where('user_id', $user->id)
        ->exists();

    if ($isQueueMember) {
        return true;
    }

    // Check if assigned
    if ($conversation->assigned_to_user_id === $user->id) {
        return true;
    }

    return false;
});

// Support user presence channel
Broadcast::channel('support.user.{userId}', function (User $user, string $userId) {
    return (int) $user->id === (int) $userId;
});

// Support queue channel for queue-level notifications
Broadcast::channel('support.queue.{queueId}', function (User $user, string $queueId) {
    if ($user->account_type === 'client') {
        return false;
    }

    if ($user->hasPermissionTo('support.view_all')) {
        return true;
    }

    return SupportQueueMember::where('queue_id', $queueId)
        ->where('user_id', $user->id)
        ->exists();
});

// Default user channel (from Laravel)
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
