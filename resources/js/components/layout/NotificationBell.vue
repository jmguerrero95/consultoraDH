<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

import { a05 } from '@/services/a05';
import { ApiError } from '@/services/http';

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
 */

const router = useRouter();

const abierto = ref(false);
const cargando = ref(false);
const notifications = ref<AppNotification[]>([]);
const unread = ref(0);

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
        await cargar();
    } catch {
        // The count is refreshed on the next poll; a failed mark does not break the menu.
    }

    await abrir(notification);
}

async function marcarTodas(): Promise<void> {
    try {
        const respuesta = await a05.notifications.markAllRead();

        unread.value = respuesta.unread;
        await cargar();
    } catch {
        await cargar();
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
        void cargar();
    }
}

onMounted(cargar);
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