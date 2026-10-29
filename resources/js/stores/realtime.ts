import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

import { csrfToken } from '@/services/http'

// Make Pusher available globally for Laravel Echo
declare global {
  interface Window {
    Pusher: typeof Pusher
  }
}
window.Pusher = Pusher

/**
 * A handler for a realtime event.
 *
 * The payload is typed by the caller, so the store does not have to know what
 * travels over a channel; `never` is what makes every concrete handler
 * assignable to it.
 */
export type ChannelCallback = (data: never) => void

/** The handlers of one channel, by event name. */
export type ChannelCallbacks = Record<string, ChannelCallback>

/** The channels this deployment talks to. Reverb speaks the Pusher protocol. */
type ReverbEcho = Echo<'reverb'>

type PrivateChannel = ReturnType<ReverbEcho['private']>
type PresenceChannel = ReturnType<ReverbEcho['join']>

/** What is remembered of a channel so it can be joined again after a reconnect. */
interface RealtimeChannelState {
  channel: PrivateChannel | PresenceChannel | null
  callbacks: ChannelCallbacks
}

/** A member of a presence channel. */
export interface PresenceMember {
  id: number
  name: string
}

/**
 * Call a handler with a payload.
 *
 * The handlers are typed by whoever registered them, and the store only knows
 * that *some* handler is there. This is the one place where the two meet.
 */
function emit(callbacks: ChannelCallbacks, event: string, payload: unknown): void {
  const handler = callbacks[event]

  if (!handler) {
    return
  }

  ;(handler as (data: unknown) => void)(payload)
}

interface RealtimeState {
  connected: boolean
  connecting: boolean
  error: string | null
  channels: Map<string, RealtimeChannelState>
}

export const useRealtimeStore = defineStore('realtime', () => {
  const state = ref<RealtimeState>({
    connected: false,
    connecting: false,
    error: null,
    channels: new Map(),
  })

  let echo: ReverbEcho | null = null

  const isConnected = computed(() => state.value.connected)
  const isConnecting = computed(() => state.value.connecting)
  const connectionError = computed(() => state.value.error)

  /**
   * Initialize the Laravel Echo connection
   */
  function initialize(): Promise<void> {
    if (echo) {
      return Promise.resolve()
    }

    state.value.connecting = true
    state.value.error = null

    return new Promise((resolve, reject) => {
      try {
        // Get the VAPID public key from the server (not used in Echo config but needed for auth)
        fetch('/api/push/vapid-public-key')
          .then(response => response.json())
          .then(_data => {
            // Initialize Laravel Echo
            echo = new Echo({
              broadcaster: 'reverb',
              key: import.meta.env.VITE_REVERB_APP_KEY || '',
              wsHost: import.meta.env.VITE_REVERB_HOST || 'localhost',
              wsPort: parseInt(import.meta.env.VITE_REVERB_PORT || '8081'),
              wssPort: parseInt(import.meta.env.VITE_REVERB_PORT || '8081'),
              forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
              disableStats: true,
              enabledTransports: ['ws', 'wss'],
              // The framework's own endpoint (registered by `channels:` in
              // bootstrap/app.php). It lives under the `web` group, so it has the
              // session and the CSRF protection that channel authorization
              // depends on. It must not be replaced by a permissive stub.
              authEndpoint: '/broadcasting/auth',
              auth: {
                headers: {
                  // `csrfToken()` returns the *decoded* `XSRF-TOKEN` cookie. That
                  // is precisely what `X-XSRF-TOKEN` expects: Laravel decrypts
                  // the header value the same way it decrypts the cookie.
                  // `X-CSRF-TOKEN` is a different contract — it wants the raw
                  // `csrf_token()` value, which this application never renders
                  // into the document. Sending the cookie under that header was
                  // rejected (419) as soon as CSRF was enforced on the endpoint.
                  'X-XSRF-TOKEN': csrfToken(),
                },
              },
            })

            echo.connector.pusher.connection.bind('connected', () => {
              state.value.connected = true
              state.value.connecting = false
              state.value.error = null
              resubscribeChannels()
              resolve()
            })

            echo.connector.pusher.connection.bind('disconnected', () => {
              state.value.connected = false
              state.value.connecting = false
            })

            echo.connector.pusher.connection.bind('error', (error: { message?: string }) => {
              state.value.connecting = false
              state.value.error = error.message || 'Connection error'
              reject(new Error(error.message || 'Connection error'))
            })

            // Set a timeout for connection
            setTimeout(() => {
              if (state.value.connecting && !state.value.connected) {
                state.value.connecting = false
                state.value.error = 'Connection timeout'
                reject(new Error('Connection timeout'))
              }
            }, 10000)
          })
          .catch(err => {
            state.value.connecting = false
            state.value.error = err.message
            reject(err)
          })
      } catch (err) {
        state.value.connecting = false
        state.value.error = err instanceof Error ? err.message : 'Unknown error'
        reject(err)
      }
    })
  }

  /**
   * Resubscribe to all channels after reconnection
   */
  function resubscribeChannels(): void {
    if (!echo) return

    state.value.channels.forEach((channel, name) => {
      if (name.startsWith('private-')) {
        const channelName = name.replace('private-', '')
        joinPrivateChannel(channelName, channel.callbacks)
      } else if (name.startsWith('presence-')) {
        const channelName = name.replace('presence-', '')
        joinPresenceChannel(channelName, channel.callbacks)
      }
    })
  }

  /**
   * Join a private channel
   */
  function joinPrivateChannel(channelName: string, callbacks: ChannelCallbacks = {}): PrivateChannel | null {
    if (!echo) {
      // Not an error, and deliberately not thrown.
      //
      // `initialize()` is asynchronous — it fetches the VAPID key before Echo
      // exists — so a caller that subscribes straight after asking for a
      // connection is racing it. Throwing here took the whole screen down: the
      // exception escaped `onMounted`, and the conversation being read rendered
      // with no subject and no error, because realtime is an enhancement the
      // reading and sending of messages do not depend on.
      state.value.error = 'Realtime no disponible'
      return null
    }

    const channelNameWithPrefix = `private-${channelName}`
    const channel = echo.private(channelName)

    // Store callbacks
    state.value.channels.set(channelNameWithPrefix, {
      channel,
      callbacks,
    })

    // Register callbacks
    Object.entries(callbacks).forEach(([event, callback]) => {
      channel.listen(event, callback)
    })

    return channel
  }

  /**
   * Join a presence channel
   */
  function joinPresenceChannel(channelName: string, callbacks: ChannelCallbacks = {}): PresenceChannel | null {
    if (!echo) {
      // Same reasoning as `joinPrivateChannel`: realtime not being available is
      // a recorded state, not an exception that takes a screen down with it.
      state.value.error = 'Realtime no disponible'
      return null
    }

    const channelNameWithPrefix = `presence-${channelName}`
    const channel = echo.join(channelName)

    // Store callbacks
    state.value.channels.set(channelNameWithPrefix, {
      channel,
      callbacks,
    })

    // Register callbacks
    Object.entries(callbacks).forEach(([event, callback]) => {
      channel.listen(event, callback)
    })

    // Handle presence events
    channel.here((members: PresenceMember[]) => {
      emit(callbacks, 'presence:here', members)
    })

    channel.joining((member: PresenceMember) => {
      emit(callbacks, 'presence:joining', member)
    })

    channel.leaving((member: PresenceMember) => {
      emit(callbacks, 'presence:leaving', member)
    })

    return channel
  }

  /**
   * Leave a channel
   */
  function leaveChannel(channelName: string): void {
    if (!echo) return

    const privateName = `private-${channelName}`
    const presenceName = `presence-${channelName}`

    if (state.value.channels.has(privateName)) {
      echo.leave(privateName)
      state.value.channels.delete(privateName)
    }

    if (state.value.channels.has(presenceName)) {
      echo.leave(presenceName)
      state.value.channels.delete(presenceName)
    }
  }

  /**
   * Listen for an event on a channel
   */
  function listen(channelName: string, event: string, callback: ChannelCallback): void {
    if (!echo) {
      throw new Error('Echo not initialized')
    }

    const privateName = `private-${channelName}`
    const channelData = state.value.channels.get(privateName)

    if (channelData?.channel) {
      channelData.channel.listen(event, callback)
      channelData.callbacks[event] = callback
    } else {
      // Channel not joined yet, store callback for when it's joined
      const channel = echo.private(channelName)
      channel.listen(event, callback)

      state.value.channels.set(`private-${channelName}`, {
        channel,
        callbacks: { [event]: callback },
      })
    }
  }

  /**
   * Stop listening for an event on a channel
   */
  function stopListening(channelName: string, event: string): void {
    if (!echo) return

    const privateName = `private-${channelName}`
    const channelData = state.value.channels.get(privateName)

    if (channelData?.channel) {
      channelData.channel.stopListening(event)
      delete channelData.callbacks[event]
    }
  }

  /**
   * Disconnect from the WebSocket
   */
  function disconnect(): void {
    if (echo) {
      echo.disconnect()
      echo = null
      state.value.connected = false
      state.value.connecting = false
      state.value.channels.clear()
    }
  }

  /**
   * Reconnect to the WebSocket
   */
  async function reconnect(): Promise<void> {
    disconnect()
    await initialize()
  }

  return {
    // State
    state,
    isConnected,
    isConnecting,
    connectionError,

    // Methods
    initialize,
    disconnect,
    reconnect,
    joinPrivateChannel,
    joinPresenceChannel,
    leaveChannel,
    listen,
    stopListening,
    resubscribeChannels,
  }
})