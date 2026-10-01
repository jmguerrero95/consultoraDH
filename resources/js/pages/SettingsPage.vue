<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { api } from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';

import type { SettingsPayload } from '@/types/api';

/**
 * Application configuration.
 *
 * Read only, and deliberately limited to what an administrator needs to
 * identify a deployment. It never renders environment file content, host
 * names, connection strings or secrets. Access is enforced on the server by the
 * `settings.view` permission; arriving here without it yields the 403 screen.
 */
const auth = useAuthStore();
const router = useRouter();

const data = ref<SettingsPayload | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

function localeName(code: string): string {
    return code === 'es' ? 'Español' : code;
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        data.value = await api.settings();
    } catch (caught) {
        if (caught instanceof ApiError && caught.isForbidden) {
            // Let the layout render the access denied screen.
            await router.replace({ name: 'forbidden' });

            return;
        }

        error.value =
            caught instanceof ApiError
                ? caught.message
                : 'No fue posible cargar la configuración.';
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
                    Información de la instalación. En esta etapa la configuración es de
                    solo lectura.
                </p>
            </div>

            <span v-if="data" class="cdh-badge cdh-badge--neutral">
                v{{ data.application.version }}
            </span>
        </div>

        <AppLoading v-if="loading" label="Cargando configuración…" />

        <AppAlert v-else-if="error" variant="danger" title="No se pudo cargar la configuración">
            <p class="mb-2">{{ error }}</p>
            <AppButton variant="secondary" @click="load">Reintentar</AppButton>
        </AppAlert>

        <div v-else-if="data" class="cdh-grid-2">
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
                            <span class="cdh-status-row__label">Versión</span>
                            <span class="cdh-status-row__value">
                                {{ data.application.version }}
                            </span>
                        </div>

                        <div class="cdh-status-row">
                            <span class="cdh-status-row__label">Entorno</span>
                            <span class="cdh-status-row__value">
                                {{ data.application.environment }}
                            </span>
                        </div>

                        <div class="cdh-status-row">
                            <span class="cdh-status-row__label">URL de la aplicación</span>
                            <span class="cdh-status-row__value cdh-mono">
                                {{ data.application.url }}
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="cdh-card" aria-labelledby="locale-heading">
                <div class="cdh-card__header">
                    <h2 id="locale-heading" class="cdh-card__title">Idioma y zona horaria</h2>
                </div>

                <div class="cdh-card__body">
                    <div class="cdh-status-list">
                        <div class="cdh-status-row">
                            <span class="cdh-status-row__label">Idioma</span>
                            <span class="cdh-status-row__value">
                                {{ localeName(data.locale.locale) }}
                            </span>
                        </div>

                        <div class="cdh-status-row">
                            <span class="cdh-status-row__label">Idioma de respaldo</span>
                            <span class="cdh-status-row__value">
                                {{ localeName(data.locale.fallback) }}
                            </span>
                        </div>

                        <div class="cdh-status-row">
                            <span class="cdh-status-row__label">Zona horaria</span>
                            <span class="cdh-status-row__value">{{ data.locale.timezone }}</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="cdh-card" aria-labelledby="infra-heading">
                <div class="cdh-card__header">
                    <h2 id="infra-heading" class="cdh-card__title">Infraestructura</h2>
                </div>

                <div class="cdh-card__body">
                    <div class="cdh-status-list">
                        <div class="cdh-status-row">
                            <span class="cdh-status-row__label">Base de datos</span>
                            <span class="cdh-status-row__value">
                                {{ data.infrastructure.database }}
                            </span>
                        </div>

                        <div class="cdh-status-row">
                            <span class="cdh-status-row__label">Caché</span>
                            <span class="cdh-status-row__value">
                                {{ data.infrastructure.cache }}
                            </span>
                        </div>

                        <div class="cdh-status-row">
                            <span class="cdh-status-row__label">Sesiones</span>
                            <span class="cdh-status-row__value">
                                {{ data.infrastructure.sessions }}
                            </span>
                        </div>

                        <div class="cdh-status-row">
                            <span class="cdh-status-row__label">Colas de trabajo</span>
                            <span class="cdh-status-row__value">
                                {{ data.infrastructure.queue }}
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
                            <span class="cdh-status-row__label">
                                Depuración (debug)
                            </span>
                            <span class="cdh-status-row__value">
                                <!--
                                    A visible debug flag in a production deployment is a
                                    serious problem, so it is stated plainly here.
                                -->
                                <span
                                    class="cdh-badge"
                                    :class="data.security.debug_enabled ? 'cdh-badge--danger' : 'cdh-badge--success'"
                                >
                                    {{ data.security.debug_enabled ? 'Activada' : 'Desactivada' }}
                                </span>
                            </span>
                        </div>

                        <div class="cdh-status-row">
                            <span class="cdh-status-row__label">Cookie de sesión segura (HTTPS)</span>
                            <span class="cdh-status-row__value">
                                <span
                                    class="cdh-badge"
                                    :class="data.security.session_cookie_secure ? 'cdh-badge--success' : 'cdh-badge--neutral'"
                                >
                                    {{ data.security.session_cookie_secure ? 'Sí' : 'No (solo HTTP)' }}
                                </span>
                            </span>
                        </div>

                        <div class="cdh-status-row">
                            <span class="cdh-status-row__label">
                                Política de seguridad de contenido (CSP)
                            </span>
                            <span class="cdh-status-row__value">
                                <span
                                    class="cdh-badge"
                                    :class="data.security.content_security_policy ? 'cdh-badge--success' : 'cdh-badge--neutral'"
                                >
                                    {{ data.security.content_security_policy ? 'Activa' : 'Inactiva' }}
                                </span>
                            </span>
                        </div>
                    </div>

                    <hr class="cdh-divider" />

                    <div class="cdh-status-list">
                        <div class="cdh-status-row">
                            <span class="cdh-status-row__label">Su rol</span>
                            <span class="cdh-status-row__value">
                                {{ auth.primaryRole ?? 'Sin rol' }}
                            </span>
                        </div>

                        <div class="cdh-status-row">
                            <span class="cdh-status-row__label">Permisos asignados</span>
                            <span class="cdh-status-row__value">
                                <span
                                    v-if="data.access.permissions.length === 0"
                                    class="cdh-text-muted"
                                >
                                    Ninguno
                                </span>
                                <span
                                    v-for="permission in data.access.permissions"
                                    :key="permission"
                                    class="cdh-badge cdh-badge--info me-1 cdh-mono"
                                >
                                    {{ permission }}
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
