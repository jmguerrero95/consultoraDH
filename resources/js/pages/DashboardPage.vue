<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { api } from '@/services/api';
import { ApiError } from '@/services/http';

import type { DashboardPayload, ServiceCheck } from '@/types/api';

/**
 * A01 dashboard.
 *
 * Shows what is genuinely known: who is signed in, which role it holds, the
 * running configuration, and whether PostgreSQL and Redis answer. It invents no
 * business figures, because none exist yet.
 */
const data = ref<DashboardPayload | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

function badgeFor(check: ServiceCheck): { variant: 'success' | 'danger'; label: string } {
    return check.status === 'operational'
        ? { variant: 'success', label: 'Operativo' }
        : { variant: 'danger', label: 'No disponible' };
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        data.value = await api.dashboard();
    } catch (caught) {
        error.value =
            caught instanceof ApiError
                ? caught.message
                : 'No fue posible cargar la información del sistema.';
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
                <h1 class="cdh-page-header__title">
                    {{ data ? `Hola, ${data.greeting.first_name}` : 'Inicio' }}
                </h1>
                <p class="cdh-page-header__subtitle">
                    {{ data?.greeting.date ?? 'Resumen del estado de la plataforma' }}
                </p>
            </div>

            <button type="button" class="cdh-btn cdh-btn--secondary btn btn-sm" @click="load">
                <i class="bi bi-arrow-clockwise" aria-hidden="true" />
                <span>Actualizar</span>
            </button>
        </div>

        <AppLoading v-if="loading" label="Cargando información del sistema…" />

        <AppAlert v-else-if="error" variant="danger" title="No se pudo cargar el resumen">
            {{ error }}
        </AppAlert>

        <div v-else-if="data" class="cdh-stack-5">
            <!-- Real, verifiable facts. -->
            <section aria-labelledby="summary-heading">
                <h2 id="summary-heading" class="cdh-visually-hidden">Resumen de la sesión</h2>

                <div class="cdh-grid-stats">
                    <article class="cdh-stat">
                        <p class="cdh-stat__label">
                            <i class="bi bi-person" aria-hidden="true" />
                            <span>Usuario</span>
                        </p>
                        <p class="cdh-stat__value">{{ data.user.email }}</p>
                        <p class="cdh-stat__hint">
                            {{ data.greeting.name }}
                        </p>
                    </article>

                    <article class="cdh-stat">
                        <p class="cdh-stat__label">
                            <i class="bi bi-shield-lock" aria-hidden="true" />
                            <span>Rol</span>
                        </p>
                        <p class="cdh-stat__value">{{ data.user.primary_role ?? 'Sin rol' }}</p>
                        <p class="cdh-stat__hint">
                            <span class="cdh-badge cdh-badge--info">
                                {{ data.user.status === 'active' ? 'Activo' : 'Inactivo' }}
                            </span>
                        </p>
                    </article>

                    <article class="cdh-stat">
                        <p class="cdh-stat__label">
                            <i class="bi bi-clock-history" aria-hidden="true" />
                            <span>Último ingreso</span>
                        </p>
                        <p class="cdh-stat__value">
                            {{ data.user.last_login_at ?? 'Primer ingreso' }}
                        </p>
                    </article>

                    <article class="cdh-stat">
                        <p class="cdh-stat__label">
                            <i class="bi bi-info-circle" aria-hidden="true" />
                            <span>Aplicación</span>
                        </p>
                        <p class="cdh-stat__value">v{{ data.application.version }}</p>
                        <p class="cdh-stat__hint">{{ data.application.environment }}</p>
                    </article>
                </div>
            </section>

            <!--
             | The portfolio counters. Every figure is a count against the tables
             | themselves, so an empty database shows zeroes rather than a
             | placeholder, and none of it is an estimate.
             |
             | The section is withheld entirely for a role that may not read the
             | portfolio, because "you cannot see this" and "there is nothing here"
             | are different statements.
             -->
            <section
                v-if="data.portfolio.visible && data.portfolio.counts"
                class="cdh-card mb-3"
                aria-labelledby="portfolio-heading"
            >
                <div class="cdh-card__header">
                    <div>
                        <h2 id="portfolio-heading" class="cdh-card__title">Portafolio</h2>
                        <p class="cdh-card__subtitle">
                            Cifras calculadas sobre los registros actuales.
                        </p>
                    </div>
                </div>

                <div class="cdh-card__body">
                    <div class="cdh-grid-stats">
                        <RouterLink
                            :to="{ name: 'clients' }"
                            class="cdh-stat cdh-stat--link"
                            aria-label="Ver clientes"
                        >
                            <p class="cdh-stat__label">
                                <i class="bi bi-people" aria-hidden="true" />
                                <span>Clientes activos</span>
                            </p>
                            <p class="cdh-stat__value">{{ data.portfolio.counts.active_clients }}</p>
                            <p class="cdh-stat__hint">
                                {{ data.portfolio.counts.inactive_clients }} inactivos
                            </p>
                        </RouterLink>

                        <RouterLink
                            :to="{ name: 'companies' }"
                            class="cdh-stat cdh-stat--link"
                            aria-label="Ver empresas"
                        >
                            <p class="cdh-stat__label">
                                <i class="bi bi-building" aria-hidden="true" />
                                <span>Empresas activas</span>
                            </p>
                            <p class="cdh-stat__value">{{ data.portfolio.counts.active_companies }}</p>
                            <p class="cdh-stat__hint">
                                {{ data.portfolio.counts.catalogue_entities }} entidades de seguridad social
                            </p>
                        </RouterLink>

                        <article class="cdh-stat">
                            <p class="cdh-stat__label">
                                <i class="bi bi-briefcase" aria-hidden="true" />
                                <span>Relaciones activas</span>
                            </p>
                            <p class="cdh-stat__value">{{ data.portfolio.counts.active_relationships }}</p>
                            <p class="cdh-stat__hint">
                                {{ data.portfolio.multiple_companies ?? 0 }} con más de una empresa
                            </p>
                        </article>

                        <article class="cdh-stat">
                            <p class="cdh-stat__label">
                                <i class="bi bi-hospital" aria-hidden="true" />
                                <span>Afiliaciones activas</span>
                            </p>
                            <p class="cdh-stat__value">{{ data.portfolio.counts.active_affiliations }}</p>
                            <p class="cdh-stat__hint">EPS, AFP, ARL y Cajas</p>
                        </article>

                        <article class="cdh-stat">
                            <p class="cdh-stat__label">
                                <i class="bi bi-exclamation-triangle" aria-hidden="true" />
                                <span>Alertas de calidad</span>
                            </p>
                            <p class="cdh-stat__value">
                                {{ data.portfolio.counts.data_quality_issues }}
                            </p>
                            <p class="cdh-stat__hint">
                                {{ data.portfolio.counts.data_quality_warnings }} advertencias
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <!-- Service connectivity: the only "system health" A01 reports. -->
            <section class="cdh-card" aria-labelledby="services-heading">
                <div class="cdh-card__header">
                    <div>
                        <h2 id="services-heading" class="cdh-card__title">Estado del sistema</h2>
                        <p class="cdh-card__subtitle">
                            Comprobación de conectividad con la infraestructura de la aplicación.
                        </p>
                    </div>
                </div>

                <div class="cdh-card__body">
                    <div class="cdh-status-list">
                        <div class="cdh-status-row">
                            <span class="cdh-status-row__label">Base de datos</span>
                            <span class="cdh-status-row__value">
                                <span
                                    class="cdh-badge"
                                    :class="`cdh-badge--${badgeFor(data.services.database).variant}`"
                                >
                                    {{ badgeFor(data.services.database).label }}
                                </span>
                                <span class="ms-2">
                                    {{ data.services.database.label }}
                                    <template v-if="data.services.database.detail">
                                        {{ data.services.database.detail }}
                                    </template>
                                </span>
                            </span>
                        </div>

                        <div class="cdh-status-row">
                            <span class="cdh-status-row__label">
                                Caché, sesiones y colas
                            </span>
                            <span class="cdh-status-row__value">
                                <span
                                    class="cdh-badge"
                                    :class="`cdh-badge--${badgeFor(data.services.redis).variant}`"
                                >
                                    {{ badgeFor(data.services.redis).label }}
                                </span>
                                <span class="ms-2">
                                    {{ data.services.redis.label }}
                                    <template v-if="data.services.redis.detail">
                                        {{ data.services.redis.detail }}
                                    </template>
                                </span>
                            </span>
                        </div>

                        <div class="cdh-status-row">
                            <span class="cdh-status-row__label">Zona horaria</span>
                            <span class="cdh-status-row__value">
                                {{ data.application.timezone }}
                            </span>
                        </div>

                        <div class="cdh-status-row">
                            <span class="cdh-status-row__label">Idioma de la interfaz</span>
                            <span class="cdh-status-row__value">
                                {{ data.application.locale === 'es' ? 'Español' : data.application.locale }}
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Honest empty state instead of fabricated business data. -->
            <section class="cdh-card" aria-labelledby="modules-heading">
                <div class="cdh-card__header">
                    <div>
                        <h2 id="modules-heading" class="cdh-card__title">Módulos operativos</h2>
                        <p class="cdh-card__subtitle">
                            Indicadores de periodos, cortes, pagos y planillas.
                        </p>
                    </div>
                </div>

                <div class="cdh-card__body cdh-card__body--flush">
                    <!--
                     | Scoped to what has NOT been built yet. A02 delivered the
                     | portfolio, so the counters above cover it; what follows names
                     | only the modules still to come, rather than claiming the whole
                     | operational area is empty.
                     -->
                    <AppEmptyState icon="bi-clipboard-data" title="Sin indicadores operativos todavía">
                        El portafolio de clientes, empresas y afiliaciones ya está
                        arriba. Aquí aparecerán los indicadores de periodos, cortes,
                        pagos y planillas a medida que se implementen esos módulos.
                    </AppEmptyState>
                </div>
            </section>
        </div>
    </div>
</template>
