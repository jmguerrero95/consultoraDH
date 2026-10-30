<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { api } from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';

import type { SettingsPayload } from '@/types/api';

/**
 * The configuration hub.
 *
 * Notifications, Telegram, Push and personal preferences are actionable; the
 * infrastructure and security read-only detail lives in its own screen so this
 * page is a place to decide, not a place to read a deployment dump.
 *
 * Every card is still guarded by the server permission behind it. A link that
 * is hidden for lack of a permission is a courtesy; the request is refused
 * regardless.
 */
const auth = useAuthStore();
const router = useRouter();

const data = ref<SettingsPayload | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        data.value = await api.settings();
    } catch (caught) {
        if (caught instanceof ApiError && caught.isForbidden) {
            await router.replace({ name: 'forbidden' });

            return;
        }

        error.value = caught instanceof ApiError ? caught.message : 'No fue posible cargar la configuración.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <div class="cdh-page-header">
            <div>
                <h1 class="cdh-page-header__title">Configuración</h1>
                <p class="cdh-page-header__subtitle">
                    Notificaciones, canales de aviso y el estado verificable de la instalación.
                </p>
            </div>

            <span v-if="data" class="cdh-badge cdh-badge--neutral">
                v{{ data.application.version }}
            </span>
        </div>

        <AppLoading v-if="loading" label="Cargando configuración…" />

        <AppAlert v-else-if="error" variant="danger" :title="error" class="mb-4" />

        <div v-else class="cdh-grid-2">
            <RouterLink
                v-if="auth.can('notification_channels.manage')"
                :to="{ name: 'telegram-settings' }"
                class="cdh-card text-decoration-none"
            >
                <div class="cdh-card__body">
                    <h2 class="cdh-card__title">Notificaciones por Telegram</h2>
                    <p class="text-body-secondary mb-0">
                        Bot, webhook, destinos administrativos, entregas y conciliaciones.
                    </p>
                </div>
            </RouterLink>

            <RouterLink
                v-if="auth.can('notification_channels.manage')"
                :to="{ name: 'push-settings' }"
                class="cdh-card text-decoration-none"
            >
                <div class="cdh-card__body">
                    <h2 class="cdh-card__title">Push y dispositivos</h2>
                    <p class="text-body-secondary mb-0">
                        Claves VAPID, suscripciones activas y última entrega aceptada.
                    </p>
                </div>
            </RouterLink>

            <RouterLink :to="{ name: 'notification-preferences' }" class="cdh-card text-decoration-none">
                <div class="cdh-card__body">
                    <h2 class="cdh-card__title">Mis notificaciones</h2>
                    <p class="text-body-secondary mb-0">
                        Qué avisos recibe, y en qué dispositivos y canales.
                    </p>
                </div>
            </RouterLink>

            <RouterLink :to="{ name: 'telegram-personal' }" class="cdh-card text-decoration-none">
                <div class="cdh-card__body">
                    <h2 class="cdh-card__title">Mi Telegram</h2>
                    <p class="text-body-secondary mb-0">
                        Vincule su chat personal con un código de un solo uso.
                    </p>
                </div>
            </RouterLink>

            <RouterLink
                v-if="auth.can('settings.view')"
                :to="{ name: 'system-status' }"
                class="cdh-card text-decoration-none"
            >
                <div class="cdh-card__body">
                    <h2 class="cdh-card__title">Estado del sistema</h2>
                    <p class="text-body-secondary mb-0">
                        Servicios, scheduler, colas, Reverb, Push y Telegram: qué se verificó.
                    </p>
                </div>
            </RouterLink>
        </div>
    </div>
</template>
