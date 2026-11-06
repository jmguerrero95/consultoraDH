import { computed, ref } from 'vue'

/**
 * PWA installation experience.
 *
 * The install prompt is a browser event, not an API the application can call
 * whenever it likes: Chrome fires `beforeinstallprompt` once the app qualifies,
 * and the event may only be used once. This composable is the single place that
 * captures it, so both the entry point in `app.ts` and the account menu work
 * from the same captured event rather than from two independent listeners.
 *
 * The state lives at module scope on purpose. `app.ts` and `UserMenu.vue` each
 * call the composable; if the refs were created per call, the menu would watch
 * a different prompt than the one the listener captured and the option would
 * never appear.
 */

export type PWAPlatform = 'desktop' | 'android' | 'ios' | 'unknown'

export type PromptChoice = 'accepted' | 'dismissed'

/**
 * The non-standard event Chrome and Edge dispatch. It is not in lib.dom, so it
 * is declared here instead of reaching for `any` at the call site.
 */
interface BeforeInstallPromptEvent extends Event {
    readonly platforms: string[]
    readonly userChoice: Promise<{ outcome: PromptChoice; platform: string }>
    prompt(): Promise<void>
}

interface IonStandaloneNavigator extends Navigator {
    /** iOS Safari exposes this instead of the display-mode media query. */
    readonly standalone?: boolean
}

const promptEvent = ref<BeforeInstallPromptEvent | null>(null)
const isInstalled = ref(false)
const platform = ref<PWAPlatform>('desktop')

const DISMISSED_KEY = 'cdh:pwa-banner-dismissed'

const TEMP_DISMISSED_KEY = 'cdh:pwa-banner-temp-dismissed'

let listening = false

function inBrowser(): boolean {
    return typeof window !== 'undefined' && typeof navigator !== 'undefined'
}

function localStorageOrNull(): Storage | null {
    if (!inBrowser()) {
        return null
    }

    try {
        return window.localStorage
    } catch {
        return null
    }
}

function sessionStorageOrNull(): Storage | null {
    if (!inBrowser()) {
        return null
    }

    try {
        return window.sessionStorage
    } catch {
        return null
    }
}

function readFlag(storage: Storage | null, key: string): boolean {
    try {
        return storage?.getItem(key) === '1'
    } catch {
        return false
    }
}

function writeFlag(storage: Storage | null, key: string): void {
    try {
        storage?.setItem(key, '1')
    } catch {
        // Storage can be unavailable (private mode, quota). The in-memory ref
        // still hides the offer for this session, so the UI stays consistent.
    }
}

/**
 * Dismissal is reactive state, not a storage read.
 *
 * Reading `sessionStorage` inside a computed would not track, because web
 * storage has no reactivity. The ref is seeded once from storage (so a reload
 * in the same session keeps the offer dismissed) and updated on dismissal, so
 * every consumer of the composable re-renders from the same source.
 */
const permanentDismissed = ref(readFlag(localStorageOrNull(), DISMISSED_KEY))
const tempDismissed = ref(readFlag(sessionStorageOrNull(), TEMP_DISMISSED_KEY))

function prefersStandalone(): boolean {
    if (!inBrowser()) {
        return false
    }

    const media = typeof window.matchMedia === 'function'
        ? window.matchMedia('(display-mode: standalone)')
        : null

    // iOS does not implement the display-mode query for home-screen apps; it
    // exposes `navigator.standalone` instead.
    return media?.matches === true
        || (navigator as IonStandaloneNavigator).standalone === true
}

function detectPlatform(): PWAPlatform {
    if (!inBrowser()) {
        return 'unknown'
    }

    const userAgent = navigator.userAgent

    // iPadOS reports a desktop Mac user agent; the touch point count is what
    // distinguishes it from a real Mac.
    const isIPad = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1

    if (/iPhone|iPad|iPod/.test(userAgent) || isIPad) {
        return 'ios'
    }

    if (/Android/i.test(userAgent)) {
        return 'android'
    }

    return 'desktop'
}

function refreshInstalledState(): void {
    if (!inBrowser()) {
        return
    }

    isInstalled.value = prefersStandalone()
    platform.value = detectPlatform()
}

function handleBeforeInstallPrompt(event: Event): void {
    // Without this the browser shows its own mini-infobar immediately and the
    // captured event cannot be used for our own entry point.
    event.preventDefault()

    promptEvent.value = event as BeforeInstallPromptEvent
}

function handleAppInstalled(): void {
    promptEvent.value = null
    isInstalled.value = true
}

function listen(): void {
    if (!inBrowser() || listening) {
        return
    }

    listening = true

    window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt)
    window.addEventListener('appinstalled', handleAppInstalled)

    if (typeof window.matchMedia === 'function') {
        const media = window.matchMedia('(display-mode: standalone)')
        media.addEventListener?.('change', refreshInstalledState)
    }

    refreshInstalledState()
}

export function usePWAInstallation() {
    listen()

    /** Whether the browser supplied a usable `beforeinstallprompt` event. */
    const canUseNativePrompt = computed(() => promptEvent.value !== null)

    /**
     * A single decision for both paths.
     *
     * The offer is hidden when the app is installed, when it was permanently
     * dismissed, or when it was dismissed for this session. Otherwise it is
     * shown when the native prompt exists or when there is a manual procedure
     * for the detected platform.
     */
    const showInstallOffer = computed(() => {
        if (isInstalled.value || permanentDismissed.value || tempDismissed.value) {
            return false
        }

        return canUseNativePrompt.value || platform.value !== 'unknown'
    })

    /** The native button is only rendered when an event can actually be used. */
    const canInstall = computed(() => canUseNativePrompt.value && showInstallOffer.value)

    /**
     * Use the captured prompt. The browser resolves `userChoice` exactly once;
     * the event is discarded afterwards whether it was accepted or dismissed,
     * because a spent prompt cannot be shown again.
     */
    async function install(): Promise<boolean> {
        const event = promptEvent.value

        if (event === null) {
            return false
        }

        try {
            await event.prompt()

            const choice = await event.userChoice

            promptEvent.value = null

            if (choice.outcome === 'accepted') {
                isInstalled.value = true

                return true
            }

            // A dismissal is an answer, not an error. It is remembered so the
            // banner does not follow the person around the application.
            dismissBanner()

            return false
        } catch {
            promptEvent.value = null

            return false
        }
    }

    function dismissBanner(): void {
        permanentDismissed.value = true
        writeFlag(localStorageOrNull(), DISMISSED_KEY)
    }

    function dismissBannerTemp(): void {
        tempDismissed.value = true
        writeFlag(sessionStorageOrNull(), TEMP_DISMISSED_KEY)
    }

    return {
        isInstalled,
        platform,
        canUseNativePrompt,
        showInstallOffer,
        canInstall,
        install,
        dismissBanner,
        dismissBannerTemp,
    }
}
