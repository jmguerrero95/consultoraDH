import { computed, ref, readonly } from 'vue'

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
 * a different `isInstallable` than the one the listener wrote to and the option
 * would never appear.
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
const isInstallable = ref(false)
const isInstalled = ref(false)
const showingBanner = ref(false)
const platform = ref<PWAPlatform>('desktop')

const DISMISSED_KEY = 'cdh:pwa-banner-dismissed'

let listening = false

function inBrowser(): boolean {
    return typeof window !== 'undefined' && typeof navigator !== 'undefined'
}

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

    if (isInstalled.value) {
        showingBanner.value = false
    }
}

function handleBeforeInstallPrompt(event: Event): void {
    // Without this the browser shows its own mini-infobar immediately and the
    // captured event cannot be used for our own entry point.
    event.preventDefault()

    promptEvent.value = event as BeforeInstallPromptEvent
    isInstallable.value = true
    showingBanner.value = !isInstalled.value
}

function handleAppInstalled(): void {
    promptEvent.value = null
    isInstallable.value = false
    isInstalled.value = true
    showingBanner.value = false
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
                showingBanner.value = false
                isInstallable.value = false

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
        showingBanner.value = false

        try {
            window.localStorage.setItem(DISMISSED_KEY, '1')
        } catch {
            // Storage can be unavailable (private mode, quota). The banner is
            // hidden for this session either way.
        }
    }

    function bannerWasDismissed(): boolean {
        try {
            return window.localStorage.getItem(DISMISSED_KEY) === '1'
        } catch {
            return false
        }
    }

    /** Plain text, because the menu renders it as text rather than HTML. */
    function getIOSInstructions(): string {
        return 'Para añadir Consultora DH a la pantalla de inicio, pulse Compartir y elija «Añadir a pantalla de inicio».'
    }

    return {
        promptEvent: readonly(promptEvent),
        isInstallable: readonly(isInstallable),
        isInstalled,
        showingBanner,
        platform,

        canInstall: computed(
            () => isInstallable.value
                && !isInstalled.value
                && showingBanner.value
                && !bannerWasDismissed(),
        ),

        detectPlatform,
        refreshInstalledState,
        install,
        dismissBanner,
        getIOSInstructions,
    }
}
