import { useRealtimeStore } from '@/stores/realtime'

/**
 * Composable for using the realtime store with type-safe channel subscriptions
 */
export function useRealtime() {
  const realtime = useRealtimeStore()

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
    const realtime = useRealtimeStore()

    if (!realtime.isConnected) {
      realtime.initialize()
    }

    const channel = realtime.joinPrivateChannel(`support.conversation.${conversationId}`, {
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

    return () => {
      realtime.leaveChannel(`support.conversation.${conversationId}`)
    }
  }

  /**
   * Subscribe to staff-only conversation channel (for internal notes)
   */
  function subscribeToStaffConversation(conversationId: number, callbacks: {
    onNote?: (message: any) => void
  }) {
    const realtime = useRealtimeStore()

    if (!realtime.isConnected) {
      realtime.initialize()
    }

    const channel = realtime.joinPrivateChannel(`support.staff.conversation.${conversationId}`, {
      'note.created': (data: any) => {
        callbacks.onNote?.(data.message)
      },
    })

    return () => {
      realtime.leaveChannel(`support.staff.conversation.${conversationId}`)
    }
  }

  /**
   * Subscribe to queue channel for queue-level notifications
   */
  function subscribeToQueue(queueId: number, callbacks: {
    onNewConversation?: (conversation: any) => void
    onConversationUpdate?: (data: any) => void
  }) {
    const realtime = useRealtimeStore()

    if (!realtime.isConnected) {
      realtime.initialize()
    }

    const channel = realtime.joinPrivateChannel(`support.queue.${queueId}`, {
      'conversation.created': (data: any) => {
        callbacks.onNewConversation?.(data.conversation)
      },
      'conversation.updated': (data: any) => {
        callbacks.onConversationUpdate?.(data)
      },
    })

    return () => {
      realtime.leaveChannel(`support.queue.${queueId}`)
    }
  }

  /**
   * Subscribe to user presence channel
   */
  function subscribeToPresence(userId: number, callbacks: {
    onHere?: (members: any[]) => void
    onJoining?: (member: any) => void
    onLeaving?: (member: any) => void
  }) {
    const realtime = useRealtimeStore()

    if (!realtime.isConnected) {
      realtime.initialize()
    }

    const channel = realtime.joinPresenceChannel(`support.user.${userId}`, {
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
      realtime.leaveChannel(`support.user.${userId}`)
    }
  }

  return {
    subscribeToConversation,
    subscribeToStaffConversation,
    subscribeToQueue,
    subscribeToPresence,
  }
}