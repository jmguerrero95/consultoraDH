<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { pesos } from '@/composables/useFormatters';
import { api } from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';

import type { DashboardPayload, ServiceCheck } from '@/types/api';

const auth = useAuthStore();

/**
 * Whether the payments screen is reachable for this account.
 *
 * §41. The financial section is shown to anybody who may read the portfolio, but one of its
 * cards pointed at `/payments`, whose own guard requires `payments.view`. A receivables-only
 * role was given a link that led straight to a red "forbidden" screen, which teaches
 * people that the dashboard lies. The card is rendered as a plain stat instead.
 */
const puedeVerPagos = computed(() => auth.can('payments.view'));

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
                            v-if="data.portfolio.counts.active_clients !== undefined"
                            :to="{ name: 'clients' }"
                            class="cdh-stat cdh-stat--link"
                            aria-label="Ver clientes"
                        >
                            <p class="cdh-stat__label">
                                <i class="bi bi-people" aria-hidden="true" />
                                <span>Clientes activos</span>
                            </p>
                            <p class="cdh-stat__value">{{ data.portfolio.counts.active_clients }}</p>
                            <p v-if="data.portfolio.counts.inactive_clients !== undefined" class="cdh-stat__hint">
                                {{ data.portfolio.counts.inactive_clients }} inactivos
                            </p>
                        </RouterLink>

                        <RouterLink
                            v-if="data.portfolio.counts.active_companies !== undefined"
                            :to="{ name: 'companies' }"
                            class="cdh-stat cdh-stat--link"
                            aria-label="Ver empresas"
                        >
                            <p class="cdh-stat__label">
                                <i class="bi bi-building" aria-hidden="true" />
                                <span>Empresas activas</span>
                            </p>
                            <p class="cdh-stat__value">{{ data.portfolio.counts.active_companies }}</p>
                            <p v-if="data.portfolio.counts.catalogue_entities !== undefined" class="cdh-stat__hint">
                                {{ data.portfolio.counts.catalogue_entities }} entidades de seguridad social
                            </p>
                        </RouterLink>

                        <!--
                            Every figure on this dashboard is withheld by the server
                            from roles that may not read the section it describes, so a
                            card is drawn only when its figure is actually there.
                            Rendering a zero instead would state something about the
                            portfolio that the role is not entitled to know, and an
                            absent `active_clients` would be reported as "0 clientes
                            activos", which is a false statement rather than an empty
                            card.
                        -->
                        <article v-if="data.portfolio.counts.active_relationships !== undefined" class="cdh-stat">
                            <p class="cdh-stat__label">
                                <i class="bi bi-briefcase" aria-hidden="true" />
                                <span>Relaciones activas</span>
                            </p>
                            <p class="cdh-stat__value">{{ data.portfolio.counts.active_relationships }}</p>
                            <p class="cdh-stat__hint">
                                {{ data.portfolio.multiple_companies ?? 0 }} con más de una empresa
                            </p>
                        </article>

                        <article v-if="data.portfolio.counts.active_affiliations !== undefined" class="cdh-stat">
                            <p class="cdh-stat__label">
                                <i class="bi bi-hospital" aria-hidden="true" />
                                <span>Afiliaciones activas</span>
                            </p>
                            <p class="cdh-stat__value">{{ data.portfolio.counts.active_affiliations }}</p>
                            <p class="cdh-stat__hint">EPS, AFP, ARL y Cajas</p>
                        </article>

                        <article
                            v-if="data.portfolio.counts.data_quality_issues !== undefined"
                            class="cdh-stat"
                        >
                            <p class="cdh-stat__label">
                                <i class="bi bi-exclamation-triangle" aria-hidden="true" />
                                <span>Alertas de calidad</span>
                            </p>
                            <p class="cdh-stat__value">
                                {{ data.portfolio.counts.data_quality_issues }}
                            </p>
                            <p
                                v-if="data.portfolio.counts.data_quality_warnings !== undefined"
                                class="cdh-stat__hint"
                            >
                                {{ data.portfolio.counts.data_quality_warnings }} advertencias
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <section
                v-if="data.portfolio.financial"
                class="cdh-card mb-3"
                aria-labelledby="financial-heading"
            >
                <div class="cdh-card__header">
                    <div>
                        <h2 id="financial-heading" class="cdh-card__title">Posición financiera</h2>
                        <p class="cdh-card__subtitle">
                            Calculada en el momento sobre las obligaciones y los pagos
                            registrados. Un saldo pendiente nunca vence por dejarlo quieto.
                        </p>
                    </div>
                </div>

                <div class="cdh-card__body">
                    <div class="cdh-grid-stats">
                        <RouterLink
                            :to="{ name: 'receivables' }"
                            class="cdh-stat cdh-stat--link"
                            aria-label="Ver la cartera"
                        >
                            <p class="cdh-stat__label">
                                <i class="bi bi-cash-stack" aria-hidden="true" />
                                <span>Saldo por cobrar</span>
                            </p>
                            <p class="cdh-stat__value">
                                {{ pesos(data.portfolio.financial.outstanding_balance_cop) }}
                            </p>
                            <p class="cdh-stat__hint">
                                {{ data.portfolio.financial.clients_with_debt }} clientes con saldo
                            </p>
                        </RouterLink>

                        <RouterLink
                            :to="{ name: 'receivables' }"
                            class="cdh-stat cdh-stat--link"
                            aria-label="Ver la cartera vencida"
                        >
                            <p class="cdh-stat__label">
                                <i class="bi bi-exclamation-triangle" aria-hidden="true" />
                                <span>Vencido</span>
                            </p>
                            <p
                                class="cdh-stat__value"
                                :class="{ 'cdh-danger': data.portfolio.financial.overdue_balance_cop > 0 }"
                            >
                                {{ pesos(data.portfolio.financial.overdue_balance_cop) }}
                            </p>
                            <p class="cdh-stat__hint">Obligaciones que ya pasaron su vencimiento</p>
                        </RouterLink>

                        <!--
                            §41. The link target needs `payments.view`, which a role holding
                            only `receivables.view` does not have: it was offered a navigation
                            link that the router guard immediately refused. Rendered as a
                            plain stat in that case, with no dead link anywhere.
                        -->
                        <RouterLink
                            v-if="puedeVerPagos"
                            :to="{ name: 'payments' }"
                            class="cdh-stat cdh-stat--link"
                            aria-label="Ver los pagos"
                        >
                            <p class="cdh-stat__label">
                                <i class="bi bi-wallet2" aria-hidden="true" />
                                <span>Recibido</span>
                            </p>
                            <p class="cdh-stat__value">
                                {{ pesos(data.portfolio.financial.total_received_cop) }}
                            </p>
                            <p class="cdh-stat__hint">
                                Dinero que ha llegado, aplicado o sin aplicar
                            </p>
                        </RouterLink>

                        <article v-else class="cdh-stat">
                            <p class="cdh-stat__label">
                                <i class="bi bi-wallet2" aria-hidden="true" />
                                <span>Recibido</span>
                            </p>
                            <p class="cdh-stat__value">
                                {{ pesos(data.portfolio.financial.total_received_cop) }}
                            </p>
                            <p class="cdh-stat__hint">Dinero que ha llegado</p>
                        </article>

                        <!--
                            §40. "Recaudado" was the applied figure wearing the wrong label:
                            a payment of 300000 that had not been allocated yet read as
                            nothing collected. Received, applied and credit are now three
                            separate figures, because they are three separate questions.
                        -->
                        <article class="cdh-stat">
                            <p class="cdh-stat__label">
                                <i class="bi bi-check2-circle" aria-hidden="true" />
                                <span>Aplicado a obligaciones</span>
                            </p>
                            <p class="cdh-stat__value">
                                {{ pesos(data.portfolio.financial.total_applied_cop) }}
                            </p>
                            <p class="cdh-stat__hint">
                                {{ data.portfolio.financial.payments_requiring_reconciliation }} pagos por
                                conciliar
                            </p>
                        </article>

                        <article class="cdh-stat">
                            <p class="cdh-stat__label">
                                <i class="bi bi-hourglass-split" aria-hidden="true" />
                                <span>Anticipos sin aplicar</span>
                            </p>
                            <p class="cdh-stat__value">
                                {{ pesos(data.portfolio.financial.unallocated_credit_cop) }}
                            </p>
                            <p class="cdh-stat__hint">
                                Dinero recibido cuya deuda todavía no se ha asignado
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
