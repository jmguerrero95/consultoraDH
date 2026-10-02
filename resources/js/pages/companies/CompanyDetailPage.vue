<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import AppModal from '@/components/ui/AppModal.vue';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

import type { Assignment, CompanyDetailPayload } from '@/types/api';

/**
 * The company record: who it is, who works there now, and what needs attention.
 *
 * Deactivation is deliberately not a field on this screen. It is refused while
 * any client still has an open relationship, and that is a decision with
 * consequences, so it goes through a confirmation that says what the refusal
 * means rather than a silent toggle.
 */

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const toast = useToastStore();

const id = computed(() => Number(route.params.id));

const payload = ref<CompanyDetailPayload | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const busy = ref(false);

const canUpdate = computed(() => auth.can('companies.update'));
const canChangeStatus = computed(() => auth.can('companies.change_status'));

// Withheld by the server from a role that may not read the relationships; the
// card is drawn only when the section is actually visible.
const maySeeClients = computed(() => payload.value?.clients.visible === true);
const activeClients = computed<Assignment[]>(() => payload.value?.clients.active ?? []);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        payload.value = await businessApi.companies.show(id.value);
    } catch (cause) {
        error.value = cause instanceof ApiError ? cause.message : 'No fue posible cargar la empresa.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function formatDate(value: string | null): string {
    if (value === null || value === '') {
        return '—';
    }

    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime())
        ? value
        : parsed.toLocaleDateString('es-CO', { year: 'numeric', month: '2-digit', day: '2-digit' });
}

const deactivateOpen = ref(false);
const conflict = ref<{ message: string; active_clients_count?: number } | null>(null);

async function requestDeactivate(): Promise<void> {
    busy.value = true;
    conflict.value = null;

    try {
        await businessApi.companies.changeStatus(id.value, { status: 'inactive' });

        deactivateOpen.value = false;
        toast.success('Empresa desactivada.');

        await load();
    } catch (cause) {
        if (cause instanceof ApiError && cause.status === 409) {
            // Refused because clients are still attached. The message says how
            // many, and nothing was changed.
            conflict.value = cause.payload as { message: string; active_clients_count?: number };
        } else {
            toast.error(cause instanceof ApiError ? cause.message : 'No fue posible desactivar la empresa.');
        }
    } finally {
        busy.value = false;
    }
}

async function reactivate(): Promise<void> {
    busy.value = true;

    try {
        await businessApi.companies.changeStatus(id.value, { status: 'active' });

        toast.success('Empresa reactivada.');

        await load();
    } catch (cause) {
        toast.error(cause instanceof ApiError ? cause.message : 'No fue posible reactivar la empresa.');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="cdh-content">
        <AppLoading v-if="loading" label="Cargando empresa" />

        <AppAlert v-else-if="error" variant="danger" :title="error" class="mb-4">
            <AppButton variant="secondary" @click="router.push({ name: 'companies' })">
                Volver a empresas
            </AppButton>
        </AppAlert>

        <template v-else-if="payload">
            <div class="cdh-record-header">
                <div class="cdh-record-header__identity">
                    <h1 class="cdh-page-header__title mb-0">{{ payload.company.legal_name }}</h1>
                    <p class="cdh-record-header__meta">
                        <span v-if="payload.company.tax_id_label" class="cdh-mono">
                            NIT {{ payload.company.tax_id_label }}
                        </span>
                        <span
                            class="cdh-badge"
                            :class="payload.company.status === 'active' ? 'cdh-badge--success' : 'cdh-badge--neutral'"
                        >
                            {{ payload.company.status_label }}
                        </span>
                    </p>
                </div>

                <div class="d-flex gap-2">
                    <AppButton
                        v-if="canUpdate"
                        variant="secondary"
                        icon="bi-pencil"
                        :to="{ name: 'companies.edit', params: { id } }"
                        >
Editar
</AppButton
                    >
                    <AppButton
                        v-if="canChangeStatus && payload.company.status === 'active'"
                        variant="secondary"
                        :busy="busy"
                        @click="deactivateOpen = true"
                    >
                        Desactivar
                    </AppButton>
                    <AppButton
                        v-if="canChangeStatus && payload.company.status === 'inactive'"
                        :busy="busy"
                        @click="reactivate"
                    >
                        Reactivar
                    </AppButton>
                </div>
            </div>

            <div class="cdh-grid-2">
                <article class="cdh-card">
                    <h2 class="cdh-card__title">Datos de contacto</h2>
                    <div class="cdh-card__body">
                        <dl class="cdh-status-list">
                            <div class="cdh-status-row">
                                <dt class="cdh-status-row__label">Nombre comercial</dt>
                                <dd class="cdh-status-row__value mb-0">
                                    {{ payload.company.trade_name ?? '—' }}
                                </dd>
                            </div>
                            <div class="cdh-status-row">
                                <dt class="cdh-status-row__label">Correo</dt>
                                <dd class="cdh-status-row__value mb-0">{{ payload.company.email ?? '—' }}</dd>
                            </div>
                            <div class="cdh-status-row">
                                <dt class="cdh-status-row__label">Teléfono</dt>
                                <dd class="cdh-status-row__value mb-0">{{ payload.company.phone ?? '—' }}</dd>
                            </div>
                            <div class="cdh-status-row">
                                <dt class="cdh-status-row__label">Dirección</dt>
                                <dd class="cdh-status-row__value mb-0">
                                    {{ payload.company.address ?? '—' }}
                                </dd>
                            </div>
                            <div class="cdh-status-row">
                                <dt class="cdh-status-row__label">Ciudad</dt>
                                <dd class="cdh-status-row__value mb-0">
                                    {{ payload.company.city ?? '—' }}<span v-if="payload.company.department">
                                        , {{ payload.company.department }}</span
                                    >
                                </dd>
                            </div>
                        </dl>
                    </div>
                </article>

                <article class="cdh-card">
                    <h2 class="cdh-card__title">Resumen</h2>
                    <div class="cdh-card__body">
                        <div class="cdh-grid-2">
                            <div v-if="maySeeClients" class="cdh-stat">
                                <p class="cdh-stat__value">{{ activeClients.length }}</p>
                                <p class="cdh-stat__label mb-0">Clientes activos</p>
                            </div>
                            <div v-if="maySeeClients" class="cdh-stat">
                                <p class="cdh-stat__value">{{ payload.clients.history_count ?? 0 }}</p>
                                <p class="cdh-stat__label mb-0">Clientes históricos</p>
                            </div>
                        </div>

                        <ul v-if="payload.data_quality.length > 0" class="cdh-warnings mt-3">
                            <li
                                v-for="finding in payload.data_quality"
                                :key="finding.code"
                                class="cdh-warning"
                                :class="`cdh-warning--${finding.severity}`"
                            >
                                <span class="cdh-warning__message">{{ finding.message }}</span>
                                <span v-if="finding.suggestion" class="cdh-warning__suggestion">
                                    {{ finding.suggestion }}
                                </span>
                            </li>
                        </ul>
                    </div>
                </article>
            </div>

            <!-- The client list is relationship data, and it names people. -->
            <article v-if="maySeeClients" class="cdh-card mt-3">
                <h2 class="cdh-card__title">Clientes con relación abierta</h2>
                <div class="cdh-card__body cdh-card__body--flush">
                    <AppEmptyState
                        v-if="activeClients.length === 0"
                        title="Sin clientes vinculados"
                        description="Vincule clientes desde la ficha de cada uno de ellos."
                        icon="bi-people"
                    />
                    <div v-else class="cdh-table-wrap">
                        <table class="cdh-table">
                            <thead>
                                <tr>
                                    <th scope="col">Cliente</th>
                                    <th scope="col">Cargo</th>
                                    <th scope="col">Desde</th>
                                    <th scope="col">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="assignment in activeClients" :key="assignment.id">
                                    <td data-label="Cliente">
                                        <RouterLink
                                            :to="{ name: 'clients.show', params: { id: assignment.client_id } }"
                                            class="cdh-table__link"
                                        >
                                            {{ assignment.client?.full_name ?? assignment.client_id }}
                                        </RouterLink>
                                    </td>
                                    <td data-label="Cargo">{{ assignment.job_title ?? '—' }}</td>
                                    <td data-label="Desde">{{ formatDate(assignment.started_on) }}</td>
                                    <td data-label="Estado">
                                        <span v-if="assignment.is_parallel" class="cdh-badge cdh-badge--info">
                                            En paralelo
                                        </span>
                                        <span v-else class="cdh-badge cdh-badge--success">Activa</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </article>
        </template>

        <AppModal
            :open="deactivateOpen"
            title="Desactivar empresa"
            confirm-label="Desactivar"
            destructive
            :busy="busy"
            @confirm="requestDeactivate"
            @cancel="deactivateOpen = false"
        >
            <p>
                La empresa dejará de aceptar nuevas vinculaciones. Su historial de clientes se conserva y la
                operación puede revertirse reactivándola.
            </p>

            <AppAlert
                v-if="conflict"
                variant="warning"
                :title="
                    conflict.message +
                    (conflict.active_clients_count
                        ? ` (${conflict.active_clients_count})`
                        : '')
                "
                class="mt-3"
            />
        </AppModal>
    </section>
</template>
