import { useRealtimeStore } from '@/stores/realtime'

/**
 * Composable for using the realtime store with type-safe channel subscriptions.
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
  function join(channel: string, handlers: Record<string, Function>): () => void {
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
  function subscribeToConversation(conversationId: number, callbacks: {
    onMessage?: (message: any) => void
    onNote?: (message: any) => void
    onReadUpdate?: (data: any) => void
    onConversationUpdate?: (data: any) => void
    onAssignment?: (data: any) => void
    onQueueChange?: (data: any) => void
    onResolution?: (data: any) => void
    onReopen?: (data: any) => void
  }) {
    return join(`support.conversation.${conversationId}`, {
      'message.created': (data: any) => {
        if (data.message?.message_kind === 'note') {
          callbacks.onNote?.(data.message)
        } else {
          callbacks.onMessage?.(data.message)
        }
      },
      'note.created': (data: any) => {
        callbacks.onNote?.(data.message)
      },
      'read.updated': (data: any) => {
        callbacks.onReadUpdate?.(data)
      },
      'conversation.updated': (data: any) => {
        callbacks.onConversationUpdate?.(data)
      },
      'conversation.assigned': (data: any) => {
        callbacks.onAssignment?.(data)
      },
      'conversation.queue_changed': (data: any) => {
        callbacks.onQueueChange?.(data)
      },
      'conversation.resolved': (data: any) => {
        callbacks.onResolution?.(data)
      },
      'conversation.reopened': (data: any) => {
        callbacks.onReopen?.(data)
      },
      'conversation.created': (data: any) => {
        callbacks.onConversationUpdate?.(data)
      },
    })
  }

  /**
   * Subscribe to staff-only conversation channel (for internal notes)
   */
  function subscribeToStaffConversation(conversationId: number, callbacks: {
    onNote?: (message: any) => void
  }) {
    return join(`support.staff.conversation.${conversationId}`, {
      'note.created': (data: any) => {
        callbacks.onNote?.(data.message)
      },
    })
  }

  /**
   * Subscribe to queue channel for queue-level notifications
   */
  function subscribeToQueue(queueId: number, callbacks: {
    onNewConversation?: (conversation: any) => void
    onConversationUpdate?: (data: any) => void
  }) {
    return join(`support.queue.${queueId}`, {
      'conversation.created': (data: any) => {
        callbacks.onNewConversation?.(data.conversation)
      },
      'conversation.updated': (data: any) => {
        callbacks.onConversationUpdate?.(data)
      },
    })
  }

  /**
   * Subscribe to user presence channel
   */
  function subscribeToPresence(userId: number, callbacks: {
    onHere?: (members: any[]) => void
    onJoining?: (member: any) => void
    onLeaving?: (member: any) => void
  }) {
    const channel = `support.user.${userId}`

    if (!realtime.isConnected) {
      void realtime
        .initialize()
        .catch(() => undefined)
        .finally(() => {
          realtime.joinPresenceChannel(channel, {
            'presence:here': (members: any) => {
              callbacks.onHere?.(members)
            },
            'presence:joining': (member: any) => {
              callbacks.onJoining?.(member)
            },
            'presence:leaving': (member: any) => {
              callbacks.onLeaving?.(member)
            },
          })
        })

      return () => {
        realtime.leaveChannel(channel)
      }
    }

    realtime.joinPresenceChannel(channel, {
      'presence:here': (members: any) => {
        callbacks.onHere?.(members)
      },
      'presence:joining': (member: any) => {
        callbacks.onJoining?.(member)
      },
      'presence:leaving': (member: any) => {
        callbacks.onLeaving?.(member)
      },
    })

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