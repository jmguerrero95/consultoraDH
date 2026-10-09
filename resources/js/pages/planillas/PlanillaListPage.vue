<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { useDebouncedRef } from '@/composables/useDebouncedRef';
import { pesos } from '@/composables/useFormatters';
import { useAuthStore } from '@/stores/auth';
import { a05 } from '@/services/a05';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';

import type { PlanillaSummary, VocabularyOption } from '@/types/api';

/**
 * La lista de planillas.
 *
 * Filtros por periodo, empresa, operador, estado y una búsqueda sobre la referencia
 * o el número. La lista dice cuál es el filtro aplicado, no cuál se tisgó al elegir
 * otra pestaña.
 *
 * §27: los números que se muestran (total liquidado, número de personas) los calcula
 * el servidor. Esta pantalla no suma nada.
 */

const auth = useAuthStore();

const rows = ref<PlanillaSummary[]>([]);
const loading = ref(true);
const errorMessage = ref<string | null>(null);

const search = ref('');
const periodId = ref('');
const companyId = ref('');
const operator = ref('');
const status = ref('');

const periods = ref<{ id: number; period_month: string }[]>([]);
const companies = ref<{ id: number; legal_name: string }[]>([]);
const operators = ref<VocabularyOption[]>([]);
const statuses = ref<VocabularyOption[]>([]);

const settledSearch = useDebouncedRef(search, 300);

async function cargar(): Promise<void> {
    loading.value = true;
    errorMessage.value = null;

    try {
        const respuesta = await a05.planillas.list({
            search: settledSearch.value || undefined,
            period_id: periodId.value || undefined,
            company_id: companyId.value || undefined,
            operator: operator.value || undefined,
            status: status.value || undefined,
        });

        rows.value = respuesta.data;
    } catch (e) {
        // §60: un error no es una lista vacía. Se distingue, no se traga la excepción
        // y se muestra "no hay planillas" cuando lo que ocurrió fue otra cosa.
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudieron cargar las planillas.';
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    try {
        const [vocabulario, listaPeriodos, listaEmpresas] = await Promise.all([
            a05.planillas.vocabulary(),
            businessApi.periods.list().catch(() => null),
            businessApi.companies.list().catch(() => null),
        ]);

        operators.value = vocabulario.operators;
        statuses.value = vocabulario.statuses;
        periods.value = (listaPeriodos?.items ?? []) as { id: number; period_month: string }[];
        companies.value = (listaEmpresas?.companies ?? []) as { id: number; legal_name: string }[];
    } catch {
        errorMessage.value = 'No se pudo cargar el vocabulario de planillas.';
    }

    await cargar();
});

const hayFiltros = computed(
    () =>
        search.value !== '' ||
        periodId.value !== '' ||
        companyId.value !== '' ||
        operator.value !== '' ||
        status.value !== '',
);

function limpiarFiltros(): void {
    search.value = '';
    periodId.value = '';
    companyId.value = '';
    operator.value = '';
    status.value = '';
}
</script>

<template>
    <div class="container-fluid py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0">Planillas</h1>

            <RouterLink
                v-if="auth.can('planillas.create')"
                to="/planillas/nueva"
                class="btn btn-primary"
            >
                <i class="bi bi-plus-lg me-1" aria-hidden="true" />
                Generar planilla
            </RouterLink>
        </div>

        <AppAlert v-if="errorMessage" variant="danger" :message="errorMessage" class="mb-3" />

        <form class="card mb-3" @submit.prevent="cargar">
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="buscar">Referencia o número</label>
                        <input
                            id="buscar"
                            v-model="search"
                            type="search"
                            class="form-control"
                            placeholder="Buscar…"
                        />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="periodo">Periodo</label>
                        <select id="periodo" v-model="periodId" class="form-select">
                            <option value="">Todos</option>
                            <option v-for="p in periods" :key="p.id" :value="p.id">
                                {{ p.period_month }}
                            </option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="empresa">Empresa</label>
                        <select id="empresa" v-model="companyId" class="form-select">
                            <option value="">Todas</option>
                            <option v-for="c in companies" :key="c.id" :value="c.id">
                                {{ c.legal_name }}
                            </option>
                        </select>
                    </div>
                    <div class="col-6 col-md-1">
                        <label class="form-label" for="operador">Operador</label>
                        <select id="operador" v-model="operator" class="form-select">
                            <option value="">Todos</option>
                            <option v-for="o in operators" :key="o.value" :value="o.value">
                                {{ o.label }}
                            </option>
                        </select>
                    </div>
                    <div class="col-6 col-md-1">
                        <label class="form-label" for="estado">Estado</label>
                        <select id="estado" v-model="status" class="form-select">
                            <option value="">Todos</option>
                            <option v-for="s in statuses" :key="s.value" :value="s.value">
                                {{ s.label }}
                            </option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex gap-2">
                <AppButton type="submit" variant="primary" :loading="loading">Aplicar</AppButton>
                <AppButton v-if="hayFiltros" variant="ghost" @click="limpiarFiltros">
                    Limpiar
                </AppButton>
            </div>
        </form>

        <AppLoading v-if="loading" />

        <AppEmptyState
            v-else-if="!errorMessage && rows.length === 0"
            title="Sin planillas"
            description="No hay planillas que coincidan con los filtros aplicados."
        />

        <div v-else-if="rows.length > 0" class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Planillas</caption>
                    <thead>
                        <tr>
                            <th scope="col">Empresa</th>
                            <th scope="col">Periodo</th>
                            <th scope="col">Operador</th>
                            <th scope="col">Referencia</th>
                            <th scope="col">Estado</th>
                            <th scope="col" class="text-end">Personas</th>
                            <th scope="col" class="text-end">Total liquidado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="fila in rows" :key="fila.id">
                            <td>
                                <RouterLink :to="`/planillas/${fila.id}`">
                                    {{ fila.company_name }}
                                </RouterLink>
                            </td>
                            <td>{{ fila.period_month?.slice(0, 7) ?? '—' }}</td>
                            <td>{{ fila.operator_label ?? '—' }}</td>
                            <td>
                                <span v-if="fila.reference">{{ fila.reference }}</span>
                                <span v-else-if="fila.sheet_number">{{ fila.sheet_number }}</span>
                                <span v-else class="text-body-secondary">—</span>
                            </td>
                            <td>
                                <span
                                    class="badge"
                                    :class="{
                                        'text-bg-secondary': fila.status === 'draft',
                                        'text-bg-info': fila.status === 'ready',
                                        'text-bg-primary': fila.status === 'submitted',
                                        'text-bg-success': fila.status === 'paid',
                                        'text-bg-danger': fila.status === 'cancelled',
                                    }"
                                >
                                    {{ fila.status_label }}
                                </span>
                            </td>
                            <td class="text-end">{{ fila.included_line_count }}</td>
                            <td class="text-end">{{ pesos(fila.total_liquidated_cop) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>