import { onBeforeUnmount, onMounted, ref } from 'vue';

import { ai } from '@/services/ai';

/**
 * Connectivity, as the browser reports it.
 *
 * ## Why `navigator.onLine` is not the answer
 *
 * It says whether a network interface exists, not whether anything is
 * reachable. A hotel wifi, a captive portal or a dead uplink all report "online"
 * and then fail every request. So it is used only to decide whether to *try* -
 * as a cheap hint for showing a banner - and never as evidence that something
 * worked.
 *
 * ## What is actually trusted
 *
 * The outcome of a real request. A failed write while "online" is what flips
 * this to offline, because that is the only observation that means anything.
 */
export function useConnectivity() {
    const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
    const serverReachable = ref(true);

    /** Offline means "something is not working", not "no interface". */
    const isOffline = () => !online.value || !serverReachable.value;

    function noteSuccess(): void {
        serverReachable.value = true;
    }

    /**
     * Record that a request failed.
     *
     * Only network-shaped failures count. A 422 is the server answering, so
     * treating it as "offline" would show a connectivity banner to somebody who
     * simply mistyped a field.
     */
    function noteFailure(status?: number): void {
        if (status === undefined || status === 0 || status >= 500) {
            serverReachable.value = false;
        } else {
            serverReachable.value = true;
        }
    }

    function goOnline(): void {
        online.value = true;
    }

    function goOffline(): void {
        online.value = false;
    }

    onMounted(() => {
        window.addEventListener('online', goOnline);
        window.addEventListener('offline', goOffline);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('online', goOnline);
        window.removeEventListener('offline', goOffline);
    });

    return { online, serverReachable, isOffline, noteSuccess, noteFailure };
}

/**
 * Hands a write to the service worker when it cannot be made now.
 *
 * ## Why the worker and not the page
 *
 * Because a queued write has to survive the page. If it lived in the tab, closing
 * the app to save battery would silently discard somebody's upload, and they
 * would only find out when the document was not there. The service worker is
 * still alive with no window open, and `Background Sync` gives it a callback
 * when the network returns.
 *
 * ## Why nothing is queued blindly
 *
 * Only a request that failed for network reasons, and only a `POST` to this
 * origin. A rejected request - 422, 403 - is not retried by anyone: the server
 * has already given its answer and repeating the request would not change it.
 */
export function useOutbox() {
    const queued = ref(0);

    async function queue(url: string, method: string, body: string, headers: Record<string, string> = {}): Promise<boolean> {
        const registration = await navigator.serviceWorker?.ready;

        if (!registration?.active) {
            return false;
        }

        registration.active.postMessage({ type: 'queue-request', url, method, body, headers });
        queued.value++;

        return true;
    }

    /**
     * Ask the browser to try again now.
     *
     * A no-op where Background Sync is unsupported - notably Safari, which does
     * not implement it - so the page must not depend on it being there.
     */
    async function flush(): Promise<boolean> {
        const registration = await navigator.serviceWorker?.ready;

        if (!registration?.sync) {
            return false;
        }

        try {
            await (registration as ServiceWorkerRegistration & { sync: { register(tag: string): Promise<void> } }).sync.register('cdh-outbox');

            return true;
        } catch {
            return false;
        }
    }

    return { queued, queue, flush };
}

export interface AiTaskPayload {
    id: number;
    kind: string;
    status: string;
    progress: number;
    stage: string | null;
    name: string | null;
    result: Record<string, unknown> | null;
    error: string | null;
    attempts: number;
    finished: boolean;
    recoverable: boolean;
    conversation_id: number | null;
    created_at: string | null;
    finished_at: string | null;
}

/**
 * The list of work this person has waiting, kept across reloads.
 *
 * ## Why this exists at all
 *
 * Work runs on a server. The tab that started it can be closed, the phone
 * locked, the connection dropped - and none of that stops the work. So the
 * source of truth for "is it done" is a row on the server, and this polls it
 * while the app is open.
 *
 * ## Why polling rather than a websocket
 *
 * Because the answer has to be obtainable *after* a reconnect, not streamed to
 * a tab that is no longer there. Polling is slower and much simpler, and it is
 * the only approach that works for the case this exists to handle.
 *
 * Polling stops as soon as nothing is in flight: an idle page makes no requests.
 */
export function useAiTasks() {
    const tasks = ref<AiTaskPayload[]>([]);
    const loading = ref(false);
    const error = ref<string | null>(null);

    let timer: ReturnType<typeof setTimeout> | null = null;
    let stopped = false;

    const pending = () => tasks.value.filter((task) => !task.finished);

    async function refresh(): Promise<void> {
        if (stopped) {
            return;
        }

        loading.value = true;

        try {
            const response = await ai.tasks();
            tasks.value = response.tasks;
            error.value = null;
        } catch (e) {
            // Silence, not an alarm: the work is still running on the server and
            // this is only a view of it. An error banner here would claim the
            // job failed when it has not.
            error.value = e instanceof Error ? e.message : String(e);
        } finally {
            loading.value = false;
            schedule();
        }
    }

    function schedule(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }

        if (stopped) {
            return;
        }

        // Poll only while something is unfinished.
        if (pending().length === 0) {
            return;
        }

        timer = setTimeout(() => void refresh(), 2000);
    }

    async function cancel(id: number): Promise<void> {
        await ai.cancelTask(id);
        await refresh();
    }

    onMounted(() => void refresh());
    onBeforeUnmount(() => {
        stopped = true;
        if (timer !== null) {
            clearTimeout(timer);
        }
    });

    return { tasks, pending, loading, error, refresh, cancel };
}
