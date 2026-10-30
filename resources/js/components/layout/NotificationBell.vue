<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

import { a05 } from '@/services/a05';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';
import { useRealtimeStore } from '@/stores/realtime';

import type { AppNotification } from '@/types/api';

/**
 * §59: the notification bell.
 *
 * An unread count, the most recent notifications, and a way to mark them read. This is
 * deliberately **not** chat: A05 needs a task reminder and a finished scheduled report
 * to be visible to the person they concern, and nothing more.
 *
 * The list carries a safe title, a safe message and a route. It never carries a dump of
 * the underlying record — a debtor's identifier does not belong in a table rendered on
 * every screen — and following the link still passes the same authorisation as any
 * other request.
 *
 * ## Two requests, not one (PERF-01 B03)
 *
 * The bell is mounted on every authenticated screen and is usually not opened.
 * Loading the fifteen most recent notification bodies on every mount made every
 * page pay for a list almost nobody read. Now the first request is
 * `GET /api/notifications/unread-count` — one indexed count — and the list is
 * only fetched when the dropdown is opened. The count is revalidated when the
 * tab becomes visible again instead of polling on a timer, so a hidden tab
 * costs nothing.
 */

const router = useRouter();
const auth = useAuthStore();
const realtime = useRealtimeStore();

const abierto = ref(false);
const cargando = ref(false);
const notifications = ref<AppNotification[]>([]);
const unread = ref(0);

/** Badge only: cheap, and safe to run on every page and on focus. */
async function cargarContador(): Promise<void> {
    try {
        const respuesta = await a05.notifications.unreadCount();

        unread.value = respuesta.unread;
    } catch {
        // A failed count is not an inbox with zero unread; the badge stays as it was.
    }
}

async function cargar(): Promise<void> {
    cargando.value = true;

    try {
        const respuesta = await a05.notifications.recent();

        notifications.value = respuesta.data;
        unread.value = respuesta.unread;
    } catch (e) {
        // §60: a failed request is not an empty inbox. The list stays as it was.
        if (!(e instanceof ApiError)) {
            return;
        }
    } finally {
        cargando.value = false;
    }
}

async function marcarLeida(notification: AppNotification): Promise<void> {
    if (notification.read_at !== null) {
        await abrir(notification);

        return;
    }

    try {
        const respuesta = await a05.notifications.markRead(notification.id);

        unread.value = respuesta.unread;
        // The server returned the new count; the item on screen is the one that
        // changed, so there is no reason to re-download the whole list.
        notifications.value = notifications.value.map((item) =>
            item.id === notification.id ? { ...item, read_at: new Date().toISOString() } : item,
        );
    } catch {
        // The count is revalidated on the next focus or open; a failed mark does not break the menu.
    }

    await abrir(notification);
}

async function marcarTodas(): Promise<void> {
    try {
        const respuesta = await a05.notifications.markAllRead();

        unread.value = respuesta.unread;
        const now = new Date().toISOString();
        notifications.value = notifications.value.map((item) =>
            item.read_at === null ? { ...item, read_at: now } : item,
        );
    } catch {
        // Keep the visible state; the badge is revalidated when the panel reopens.
    }
}

async function abrir(notification: AppNotification): Promise<void> {
    const destino = notification.data.route;

    abierto.value = false;

    if (typeof destino === 'string' && destino !== '') {
        await router.push(destino);
    }
}

function alternar(): void {
    abierto.value = !abierto.value;

    if (abierto.value) {
        // Bodies are only needed when somebody looks at them.
        void cargar();
    }
}

/** Revalidate the badge when the tab returns, without a timer running. */
function onVisible(): void {
    if (document.visibilityState === 'visible') {
        void cargarContador();
    }
}

/**
 * Realtime: one logical subscription per tab.
 *
 * The server emits after the database commit and only carries the change and
 * the resulting count. Bursts are coalesced into one count request, because a
 * scheduler run that stores five reminders is one badge update, not five.
 */
let refreshTimer: ReturnType<typeof setTimeout> | null = null;

function scheduleRefresh(): void {
    if (refreshTimer !== null) {
        clearTimeout(refreshTimer);
    }

    refreshTimer = setTimeout(() => {
        refreshTimer = null;
        void cargarContador();

        if (abierto.value) {
            void cargar();
        }
    }, 250);
}

function onNotificationState(payload: unknown): void {
    if (typeof payload === 'object' && payload !== null && 'unread' in payload) {
        const unreadValue = (payload as { unread?: unknown }).unread;

        if (typeof unreadValue === 'number') {
            unread.value = unreadValue;
        }
    }

    // If this panel is open, its list is stale even when the count agrees.
    if (abierto.value) {
        scheduleRefresh();
    }
}

let realtimeSubscribed = false;

async function subscribeRealtime(): Promise<void> {
    const userId = auth.user?.id;

    if (userId === undefined || realtimeSubscribed) {
        return;
    }

    try {
        await realtime.initialize();

        realtime.joinPrivateChannel(`App.Models.User.${userId}`, {
            'notification.state': onNotificationState,
        });

        realtimeSubscribed = true;
    } catch {
        // Realtime is an enhancement. The count is still correct on mount and
        // on focus, and the screen must not fail because a socket did not open.
    }
}

// A reconnect can have missed broadcasts. The badge is re-read once when the
// connection comes back; there is no polling interval behind this.
watch(
    () => realtime.isConnected,
    (connected) => {
        if (connected) {
            void cargarContador();
        }
    },
);

onMounted(() => {
    void cargarContador();
    void subscribeRealtime();
    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener('focus', cargarContador);
});

onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', onVisible);
    window.removeEventListener('focus', cargarContador);

    if (refreshTimer !== null) {
        clearTimeout(refreshTimer);
        refreshTimer = null;
    }
});
</script>

<template>
    <div class="position-relative">
        <button
            type="button"
            class="cdh-icon-btn"
            aria-label="Notificaciones"
            title="Notificaciones"
            :aria-expanded="abierto"
            data-testid="notifications-toggle"
            @click="alternar"
        >
            <i class="bi bi-bell" aria-hidden="true" />
            <span
                v-if="unread > 0"
                class="badge text-bg-danger position-absolute top-0 start-100 translate-middle"
                data-testid="notifications-unread"
            >
                {{ unread }}
            </span>
        </button>

        <div
            v-if="abierto"
            class="dropdown-menu dropdown-menu-end show position-absolute mt-2 p-0"
            style="width: 22rem; max-width: 90vw"
            role="menu"
            data-testid="notifications-panel"
        >
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                <strong class="small">Notificaciones</strong>
                <button
                    v-if="unread > 0"
                    type="button"
                    class="btn btn-sm btn-link p-0"
                    @click="marcarTodas"
                >
                    Marcar todas
                </button>
            </div>

            <div class="p-3 text-body-secondary small" v-if="cargando">
                Cargando…
            </div>

            <div v-else-if="notifications.length === 0" class="p-3 text-body-secondary small">
                No hay notificaciones.
            </div>

            <div v-else class="list-group list-group-flush" style="max-height: 20rem; overflow-y: auto">
                <button
                    v-for="notification in notifications"
                    :key="notification.id"
                    type="button"
                    class="list-group-item list-group-item-action border-0 text-start"
                    :class="{ 'fw-semibold': notification.read_at === null }"
                    role="menuitem"
                    @click="marcarLeida(notification)"
                >
                    <span class="d-block small">
                        {{ notification.data.title ?? 'Notificación' }}
                    </span>
                    <span class="d-block small text-body-secondary">
                        {{ notification.data.message ?? '' }}
                    </span>
                </button>
            </div>
        </div>
    </div>
</template>