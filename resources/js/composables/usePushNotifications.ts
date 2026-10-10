import { ref, computed, readonly } from 'vue'
import { api } from '@/services/api'

/**
 * Web Push subscription for the signed-in user.
 *
 * The browser owns the subscription; the server only stores it. That split is
 * why enabling this cannot be done from the server side: the private key never
 * leaves the browser, and the endpoint only means anything to the push service
 * that issued it.
 *
 * Every step can fail on its own, and the states are reported separately so the
 * UI can say something true: a denied permission is not the same as a browser
 * that does not support push at all, and neither is the same as a deployment
 * with no VAPID keys configured.
 */

/** A stored subscription id, kept so the row can be deleted when unsubscribing. */
const storedSubscriptionId = ref<number | null>(null)
const permission = ref<NotificationPermission | 'unsupported'>('default')
const busy = ref(false)
const error = ref<string | null>(null)

function supported(): boolean {
    return typeof window !== 'undefined'
        && 'serviceWorker' in navigator
        && 'PushManager' in window
        && 'Notification' in window
}

/**
 * Decode a base64url VAPID key into the Uint8Array the browser expects.
 *
 * The key arrives as base64url, which is not the same alphabet as base64: the
 * padding is gone and `-` and `_` stand in for `+` and `/`.
 */
function urlBase64ToUint8Array(base64String: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')

    const raw = window.atob(base64)
    // Allocated over a plain ArrayBuffer rather than the generic default: the
    // Push API rejects a SharedArrayBuffer-backed view.
    const output = new Uint8Array(new ArrayBuffer(raw.length))
    for (let i = 0; i < raw.length; i++) {
        output[i] = raw.charCodeAt(i)
    }

    return output
}

async function currentSubscription(): Promise<PushSubscription | null> {
    if (!supported()) {
        return null
    }

    const registration = await navigator.serviceWorker.ready

    return registration.pushManager.getSubscription()
}

/**
 * Whether this browser is already subscribed. Separate from `permission`,
 * because permission can be "granted" while no subscription exists.
 */
const subscribed = ref(false)

export function usePushNotifications() {
    async function refresh(): Promise<void> {
        if (!supported()) {
            permission.value = 'unsupported'
            return
        }

        permission.value = Notification.permission
        subscribed.value = (await currentSubscription()) !== null
    }

    /**
     * Ask for permission and register the subscription.
     *
     * Returns false rather than throwing when the user declines: a refusal is
     * an answer, not an error, and the caller should simply leave the toggle
     * off.
     */
    async function enable(): Promise<boolean> {
        if (!supported()) {
            error.value = 'Este navegador no admite notificaciones push'
            return false
        }

        busy.value = true
        error.value = null

        try {
            const result = await Notification.requestPermission()
            permission.value = result

            if (result !== 'granted') {
                return false
            }

            const { public_key: publicKey } = await api.push.vapidPublicKey()

            const registration = await navigator.serviceWorker.ready
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(publicKey),
            })

            const json = subscription.toJSON() as { endpoint?: string; keys?: { p256dh?: string; auth?: string } }

            if (!json.endpoint || !json.keys?.p256dh || !json.keys?.auth) {
                throw new Error('La suscripción no trae los datos necesarios')
            }

            const stored = await api.push.storeSubscription({
                endpoint: json.endpoint,
                keys: { p256dh: json.keys.p256dh, auth: json.keys.auth },
            })

            storedSubscriptionId.value = stored.id ?? null
            subscribed.value = true

            return true
        } catch (e: unknown) {
            error.value = e instanceof Error ? e.message : 'No se pudo activar las notificaciones'
            return false
        } finally {
            busy.value = false
        }
    }

    /**
     * Remove the browser subscription and tell the server to drop its row.
     *
     * The local subscription goes first: if the request fails the user is
     * never going to receive anything again, and a server row that outlives
     * its endpoint is only ever tried and then discarded.
     */
    async function disable(): Promise<boolean> {
        busy.value = true
        error.value = null

        try {
            const subscription = await currentSubscription()
            await subscription?.unsubscribe()
            subscribed.value = false

            if (storedSubscriptionId.value !== null) {
                await api.push.destroySubscription(storedSubscriptionId.value)
                storedSubscriptionId.value = null
            }

            return true
        } catch (e: unknown) {
            error.value = e instanceof Error ? e.message : 'No se pudo desactivar las notificaciones'
            return false
        } finally {
            busy.value = false
        }
    }

    return {
        supported: computed(() => supported()),
        permission: readonly(permission),
        subscribed: readonly(subscribed),
        busy: readonly(busy),
        error: readonly(error),
        refresh,
        enable,
        disable,
    }
}