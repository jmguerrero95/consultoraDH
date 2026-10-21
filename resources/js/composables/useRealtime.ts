import { useRealtimeStore } from '@/stores/realtime'
import type { ChannelCallbacks, PresenceMember } from '@/stores/realtime'

/**
 * The payload of an event that carries a message.
 */
export interface RealtimeMessageEvent<TMessage> {
  message: TMessage
}

/**
 * The payload of an event that carries a conversation.
 *
 * The server sends more than the conversation on some events — who answered,
 * which queue it moved to — so the envelope keeps the rest reachable without
 * pretending to know it.
 */
export interface RealtimeConversationEvent<TConversation> {
  conversation?: TConversation
  [key: string]: unknown
}

/**
 * Composable for using the realtime store with type-safe channel subscriptions.
 *
 * The message and the conversation are typed by the caller, because the pages
 * already declare what they expect: a page that renders a conversation has its
 * own `Conversation` and its own `Message`, and the channel only says "a
 * message arrived".
 */
export function useRealtime() {
  const realtime = useRealtimeStore()

  /**
   * Join a channel, once the connection actually exists.
   *
   * `initialize()` is asynchronous — it fetches the VAPID public key before Echo
   * is constructed — so a caller that subscribed straight after asking for a
   * connection was racing it and lost the subscription every time. The throw from
   * `joinPrivateChannel` on a missing Echo then escaped `onMounted` and took the
   * whole screen down: the conversation rendered with no subject and no error,
   * because realtime is an enhancement that reading and sending messages over
   * HTTP do not depend on.
   *
   * So the ordering is fixed here, in one place, and a connection that never
   * arrives is recorded by the store and shrugged off. Callers get an
   * unsubscribe function either way.
   */
  function join(channel: string, handlers: ChannelCallbacks): () => void {
    if (!realtime.isConnected) {
      void realtime
        .initialize()
        .catch(() => undefined)
        .finally(() => {
          realtime.joinPrivateChannel(channel, handlers)
        })
    } else {
      realtime.joinPrivateChannel(channel, handlers)
    }

    return () => {
      realtime.leaveChannel(channel)
    }
  }

  /**
   * Subscribe to a support conversation channel
   */
  function subscribeToConversation<TMessage extends { message_kind?: string }, TConversation>(
    conversationId: number,
    callbacks: {
      onMessage?: (message: TMessage) => void
      onNote?: (message: TMessage) => void
      onReadUpdate?: (data: RealtimeConversationEvent<TConversation>) => void
      onConversationUpdate?: (data: RealtimeConversationEvent<TConversation>) => void
      onAssignment?: (data: RealtimeConversationEvent<TConversation>) => void
      onQueueChange?: (data: RealtimeConversationEvent<TConversation>) => void
      onResolution?: (data: RealtimeConversationEvent<TConversation>) => void
      onReopen?: (data: RealtimeConversationEvent<TConversation>) => void
    },
  ) {
    return join(`support.conversation.${conversationId}`, {
      'message.created': (data: RealtimeMessageEvent<TMessage>) => {
        if (data.message?.message_kind === 'note') {
          callbacks.onNote?.(data.message)
        } else {
          callbacks.onMessage?.(data.message)
        }
      },
      'note.created': (data: RealtimeMessageEvent<TMessage>) => {
        callbacks.onNote?.(data.message)
      },
      'read.updated': (data: RealtimeConversationEvent<TConversation>) => {
        callbacks.onReadUpdate?.(data)
      },
      'conversation.updated': (data: RealtimeConversationEvent<TConversation>) => {
        callbacks.onConversationUpdate?.(data)
      },
      'conversation.assigned': (data: RealtimeConversationEvent<TConversation>) => {
        callbacks.onAssignment?.(data)
      },
      'conversation.queue_changed': (data: RealtimeConversationEvent<TConversation>) => {
        callbacks.onQueueChange?.(data)
      },
      'conversation.resolved': (data: RealtimeConversationEvent<TConversation>) => {
        callbacks.onResolution?.(data)
      },
      'conversation.reopened': (data: RealtimeConversationEvent<TConversation>) => {
        callbacks.onReopen?.(data)
      },
      'conversation.created': (data: RealtimeConversationEvent<TConversation>) => {
        callbacks.onConversationUpdate?.(data)
      },
    })
  }

  /**
   * Subscribe to staff-only conversation channel (for internal notes)
   */
  function subscribeToStaffConversation<TMessage>(
    conversationId: number,
    callbacks: {
      onNote?: (message: TMessage) => void
    },
  ) {
    return join(`support.staff.conversation.${conversationId}`, {
      'note.created': (data: RealtimeMessageEvent<TMessage>) => {
        callbacks.onNote?.(data.message)
      },
    })
  }

  /**
   * Subscribe to queue channel for queue-level notifications
   */
  function subscribeToQueue<TConversation>(
    queueId: number,
    callbacks: {
      onNewConversation?: (conversation: TConversation) => void
      onConversationUpdate?: (data: RealtimeConversationEvent<TConversation>) => void
    },
  ) {
    return join(`support.queue.${queueId}`, {
      'conversation.created': (data: RealtimeConversationEvent<TConversation>) => {
        if (data.conversation !== undefined) {
          callbacks.onNewConversation?.(data.conversation)
        }
      },
      'conversation.updated': (data: RealtimeConversationEvent<TConversation>) => {
        callbacks.onConversationUpdate?.(data)
      },
    })
  }

  /**
   * Subscribe to user presence channel
   */
  function subscribeToPresence(
    userId: number,
    callbacks: {
      onHere?: (members: PresenceMember[]) => void
      onJoining?: (member: PresenceMember) => void
      onLeaving?: (member: PresenceMember) => void
    },
  ) {
    const channel = `support.user.${userId}`

    const handlers: ChannelCallbacks = {
      'presence:here': (members: PresenceMember[]) => {
        callbacks.onHere?.(members)
      },
      'presence:joining': (member: PresenceMember) => {
        callbacks.onJoining?.(member)
      },
      'presence:leaving': (member: PresenceMember) => {
        callbacks.onLeaving?.(member)
      },
    }

    if (!realtime.isConnected) {
      void realtime
        .initialize()
        .catch(() => undefined)
        .finally(() => {
          realtime.joinPresenceChannel(channel, handlers)
        })

      return () => {
        realtime.leaveChannel(channel)
      }
    }

    realtime.joinPresenceChannel(channel, handlers)

    return () => {
      realtime.leaveChannel(channel)
    }
  }

  /**
   * Leave a channel by name
   */
  function leaveChannel(channelName: string) {
    realtime.leaveChannel(channelName)
  }

  return {
    subscribeToConversation,
    subscribeToStaffConversation,
    subscribeToQueue,
    subscribeToPresence,
    leaveChannel,
  }
}
