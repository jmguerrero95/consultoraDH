<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { api } from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

import type { SystemCheck, SystemStatusPayload } from '@/types/api';

/**
 * The honest state of the deployment.
 *
 * `configured` is not `connected`, and `connected` is not `verified`. Each row
 * says which of the three its evidence supports, and external providers are
 * only contacted from the explicit button, never while the screen renders.
 */
const auth = useAuthStore();
const router = useRouter();
const toast = useToastStore();

const data = ref<SystemStatusPayload | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const checking = ref(false);

const order = [
    'postgresql',
    'redis',
    'sessions',
    'queue',
    'workers',
    'scheduler',
    'reverb',
    'webpush',
    'telegram',
];

const checks = computed<[string, SystemCheck][]>(() => {
    if (data.value === null) {
        return [];
    }

    return order
        .filter((key) => data.value?.services[key] !== undefined)
        .map((key) => [key, data.value?.services[key] as SystemCheck]);
});

function localeName(code: string): string {
    return code === 'es' ? 'Español' : code;
}

function badgeClass(state: string): string {
    return {
        verified: 'cdh-badge--success',
        connected: 'cdh-badge--info',
        configured: 'cdh-badge--neutral',
        degraded: 'cdh-badge--danger',
        unknown: 'cdh-badge--warning',
    }[state] ?? 'cdh-badge--neutral';
}

function stateLabel(state: string): string {
    return {
        verified: 'Verificado',
        connected: 'Conectado',
        configured: 'Configurado',
        degraded: 'Degradado',
        unknown: 'Sin evidencia',
    }[state] ?? state;
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        data.value = await api.systemStatus.show();
    } catch (caught) {
        if (caught instanceof ApiError && caught.isForbidden) {
            await router.replace({ name: 'forbidden' });

            return;
        }

        error.value = caught instanceof ApiError ? caught.message : 'No fue posible cargar el estado.';
    } finally {
        loading.value = false;
    }
}

async function checkTelegram(): Promise<void> {
    checking.value = true;

    try {
        const result = await api.systemStatus.check('telegram');
        toast.success(
            typeof result.telegram.detail === 'string'
                ? result.telegram.detail
                : 'Comprobación ejecutada.',
        );
        await load();
    } catch (caught) {
        toast.error(caught instanceof ApiError ? caught.message : 'No fue posible comprobar Telegram.');
    } finally {
        checking.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <div class="cdh-page-header">
            <div>
                <h1 class="cdh-page-header__title">Estado del sistema</h1>
                <p class="cdh-page-header__subtitle">
                    Qué está configurado, qué respondió de verdad y qué no se puede probar.
                </p>
            </div>

            <span v-if="data" class="cdh-badge cdh-badge--neutral">
                v{{ data.application.version }}
            </span>
        </div>

        <AppLoading v-if="loading" label="Comprobando el estado…" />

        <AppAlert v-else-if="error" variant="danger" title="No se pudo cargar el estado">
            <p class="mb-2">{{ error }}</p>
            <AppButton variant="secondary" @click="load">Reintentar</AppButton>
        </AppAlert>

        <div v-else-if="data" class="cdh-stack-4">
            <section class="cdh-card" aria-labelledby="services-heading">
                <div class="cdh-card__header d-flex justify-content-between align-items-center">
                    <h2 id="services-heading" class="cdh-card__title">Servicios</h2>
                    <AppButton variant="secondary" :disabled="checking" @click="checkTelegram">
                        {{ checking ? 'Comprobando…' : 'Comprobar integración de Telegram' }}
                    </AppButton>
                </div>

                <div class="cdh-card__body">
                    <div class="cdh-status-list">
                        <div v-for="[key, check] in checks" :key="key" class="cdh-status-row">
                            <span class="cdh-status-row__label">{{ check.label }}</span>
                            <span class="cdh-status-row__value">
                                <span class="cdh-badge" :class="badgeClass(check.state)">
                                    {{ stateLabel(check.state) }}
                                </span>
                                <span class="d-block small text-body-secondary mt-1">
                                    {{ check.detail }}
                                </span>
                                <span
                                    v-if="check.latency_ms !== undefined && check.latency_ms !== null"
                                    class="d-block small text-body-secondary"
                                >
                                    {{ check.latency_ms }} ms
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <div class="cdh-grid-2">
                <section class="cdh-card" aria-labelledby="app-heading">
                    <div class="cdh-card__header">
                        <h2 id="app-heading" class="cdh-card__title">Aplicación</h2>
                    </div>
                    <div class="cdh-card__body">
                        <div class="cdh-status-list">
                            <div class="cdh-status-row">
                                <span class="cdh-status-row__label">Nombre</span>
                                <span class="cdh-status-row__value">{{ data.application.name }}</span>
                            </div>
                            <div class="cdh-status-row">
                                <span class="cdh-status-row__label">Entorno</span>
                                <span class="cdh-status-row__value">{{ data.application.environment }}</span>
                            </div>
                            <div class="cdh-status-row">
                                <span class="cdh-status-row__label">URL</span>
                                <span class="cdh-status-row__value cdh-mono">{{ data.application.url }}</span>
                            </div>
                            <div class="cdh-status-row">
                                <span class="cdh-status-row__label">Idioma y zona</span>
                                <span class="cdh-status-row__value">
                                    {{ localeName(data.locale.locale) }} · {{ data.locale.timezone }}
                                </span>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="cdh-card" aria-labelledby="security-heading">
                    <div class="cdh-card__header">
                        <h2 id="security-heading" class="cdh-card__title">Seguridad</h2>
                    </div>
                    <div class="cdh-card__body">
                        <div class="cdh-status-list">
                            <div class="cdh-status-row">
                                <span class="cdh-status-row__label">Depuración</span>
                                <span class="cdh-status-row__value">
                                    <span
                                        class="cdh-badge"
                                        :class="data.security.debug_enabled ? 'cdh-badge--danger' : 'cdh-badge--success'"
                                    >
                                        {{ data.security.debug_enabled ? 'Activada' : 'Desactivada' }}
                                    </span>
                                </span>
                            </div>
                            <div class="cdh-status-row">
                                <span class="cdh-status-row__label">Cookie segura</span>
                                <span class="cdh-status-row__value">
                                    {{ data.security.session_cookie_secure ? 'Sí' : 'No' }}
                                </span>
                            </div>
                            <div class="cdh-status-row">
                                <span class="cdh-status-row__label">CSP</span>
                                <span class="cdh-status-row__value">
                                    {{ data.security.content_security_policy ? 'Activa' : 'Inactiva' }}
                                </span>
                            </div>
                            <div class="cdh-status-row">
                                <span class="cdh-status-row__label">Su rol</span>
                                <span class="cdh-status-row__value">{{ auth.primaryRole ?? 'Sin rol' }}</span>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <section class="cdh-card" aria-labelledby="permissions-heading">
                <div class="cdh-card__header">
                    <h2 id="permissions-heading" class="cdh-card__title">Permisos asignados</h2>
                </div>
                <div class="cdh-card__body">
                    <span
                        v-for="permission in data.access.permissions"
                        :key="permission"
                        class="cdh-badge cdh-badge--info me-1 mb-1 cdh-mono"
                    >
                        {{ permission }}
                    </span>
                    <span v-if="data.access.permissions.length === 0" class="cdh-text-muted">
                        Ninguno
                    </span>
                </div>
            </section>

            <p class="small text-body-secondary">
                Última lectura: {{ data.checked_at }}
            </p>
        </div>
    </div>
</template>
