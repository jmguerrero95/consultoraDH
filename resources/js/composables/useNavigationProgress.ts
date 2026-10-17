import { readonly, ref } from 'vue';

/**
 * Whether a route change is currently in flight.
 *
 * ## Why this exists
 *
 * Pages are lazily imported, so the first time somebody opens a module the router has
 * to download that module's chunk before it can render anything. Until then nothing on
 * screen changes, and a click that is working looks like a click that was ignored — so
 * the reader clicks again, or presses another control, and the screen jumps when the
 * first chunk finally lands. That is the "the menu needs several clicks" report.
 *
 * A route change is also the one operation the interface cannot express through the
 * button that started it: the button is gone as soon as the new screen mounts. A thin
 * bar at the top of the document is the feedback for exactly this case.
 */
const navigating = ref(false);

/** Overlapping (redirected) navigations must not let the first one clear the bar. */
let pending = 0;
let watchdog: ReturnType<typeof setTimeout> | null = null;

const WATCHDOG_MS = 15_000;

function armWatchdog(): void {
    if (watchdog !== null) {
        clearTimeout(watchdog);
    }

    // A safety net, not a timeout for the request: if a navigation never reports back
    // (an aborted one, a bug in a guard) the bar must not stay on screen forever.
    watchdog = setTimeout(() => {
        pending = 0;
        navigating.value = false;
        watchdog = null;
    }, WATCHDOG_MS);
}

export function startNavigation(): void {
    pending += 1;
    navigating.value = true;
    armWatchdog();
}

export function finishNavigation(): void {
    pending = Math.max(0, pending - 1);

    if (pending === 0) {
        navigating.value = false;

        if (watchdog !== null) {
            clearTimeout(watchdog);
            watchdog = null;
        }
    }
}

/** Read-only, so only the router can drive it. */
export function useNavigationProgress() {
    return { navigating: readonly(navigating) };
}
