import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

// Make Pusher available globally for Laravel Echo
window.Pusher = Pusher

interface RealtimeState {
  connected: boolean
  connecting: boolean
  error: string | null
  channels: Map<string, any>
}

export const useRealtimeStore = defineStore('realtime', () => {
  const state = ref<RealtimeState>({
    connected: false,
    connecting: false,
    error: null,
    channels: new Map(),
  })

  let echo: Echo | null = null

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
        // Get the VAPID public key from the server
        fetch('/api/push/vapid-public-key')
          .then(response => response.json())
          .then(data => {
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
              authEndpoint: '/api/broadcasting/auth',
              auth: {
                headers: {
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
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

            echo.connector.pusher.connection.bind('error', (error: any) => {
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
  function joinPrivateChannel(channelName: string, callbacks: Record<string, Function> = {}): any {
    if (!echo) {
      throw new Error('Echo not initialized')
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
  function joinPresenceChannel(channelName: string, callbacks: Record<string, Function> = {}): any {
    if (!echo) {
      throw new Error('Echo not initialized')
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
    channel.here((members: any[]) => {
      callbacks['presence:here']?.(members)
    })

    channel.joining((member: any) => {
      callbacks['presence:joining']?.(member)
    })

    channel.leaving((member: any) => {
      callbacks['presence:leaving']?.(member)
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
  function listen(channelName: string, event: string, callback: Function): void {
    if (!echo) {
      throw new Error('Echo not initialized')
    }

    const privateName = `private-${channelName}`
    const channelData = state.value.channels.get(privateName)

    if (channelData) {
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

    if (channelData) {
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