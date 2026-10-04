<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { useDebouncedRef } from '@/composables/useDebouncedRef';
import { etiquetaMes, fecha, pesos } from '@/composables/useFormatters';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';

import type { BillingVocabularyPayload, ReceivablesTotals, ReceivableRow } from '@/types/api';

/**
 * La cartera.
 *
 * A list of the people the business is owed money by, and nothing else. A client
 * who owes nothing is not on this screen, because "you cannot see this" and "there
 * is nothing here" are different statements and a row of zeroes would conflate them.
 *
 * The traffic light is a reading of the past, not a score of the person: it counts
 * how many of their debts have fallen due. It is shown beside the reason it gives, so
 * a colour is never the only thing on the row.
 *
 * The figures come from the server on every request. There is no figure on this
 * screen that is stored and could be stale, which is why a voided payment changes
 * the total without anybody refreshing a counter anywhere.
 */

const search = ref('');
const agingBucket = ref('');
const trafficLight = ref('');
const overdueOnly = ref(false);
const asOf = ref('');
const page = ref(1);
const perPage = ref<number>(25);

const settledSearch = useDebouncedRef(search, 300);

const rows = ref<ReceivableRow[]>([]);
const summary = ref<ReceivablesTotals | null>(null);
const total = ref(0);
const lastPage = ref(1);
const vocabulary = ref<BillingVocabularyPayload | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

let requestId = 0;

async function load(): Promise<void> {
    const current = ++requestId;

    loading.value = true;
    error.value = null;

    try {
        const payload = await businessApi.receivables.list({
            search: settledSearch.value,
            aging_bucket: agingBucket.value,
            traffic_light: trafficLight.value,
            overdue: overdueOnly.value,
            as_of: asOf.value === '' ? null : asOf.value,
            page: page.value,
            per_page: perPage.value,
        });

        if (current !== requestId) {
            return;
        }

        rows.value = payload.items;
        summary.value = payload.summary;
        total.value = payload.total;
        lastPage.value = payload.last_page;
    } catch (cause) {
        if (current !== requestId) {
            return;
        }

        rows.value = [];
        error.value =
            cause instanceof ApiError ? cause.message : 'No fue posible cargar la cartera.';
    } finally {
        if (current === requestId) {
            loading.value = false;
        }
    }
}

async function loadVocabulary(): Promise<void> {
    try {
        vocabulary.value = await businessApi.receivables.vocabulary();
    } catch {
        vocabulary.value = null;
    }
}

onMounted(() => {
    void load();
    void loadVocabulary();
});

watch([settledSearch, agingBucket, trafficLight, overdueOnly, asOf, perPage], () => {
    page.value = 1;
    void load();
});

watch(page, () => void load());

function clearFilters(): void {
    search.value = '';
    agingBucket.value = '';
    trafficLight.value = '';
    overdueOnly.value = false;
    asOf.value = '';
}

const isFiltered = computed(
    () =>
        settledSearch.value !== '' ||
        agingBucket.value !== '' ||
        trafficLight.value !== '' ||
        overdueOnly.value ||
        asOf.value !== '',
);

const rangeLabel = computed(() => {
    if (total.value === 0) {
        return 'Sin resultados';
    }

    const from = (page.value - 1) * perPage.value + 1;
    const to = Math.min(page.value * perPage.value, total.value);

    return `${from}–${to} de ${total.value}`;
});

const lightBadge: Record<string, string> = {
    green: 'cdh-badge--success',
    yellow: 'cdh-badge--warning',
    orange: 'cdh-badge--warning',
    red: 'cdh-badge--danger',
};

function agingLabel(row: ReceivableRow): string {
    const bucket = vocabulary.value?.aging_buckets.find(
        (option) => option.value === row.aging_bucket,
    );

    return bucket?.label ?? row.aging_bucket;
}

/**
 * The months this client still owes, named.
 *
 * `owed_periods` arrives as `YYYY-MM` keys because that is what the server aggregates and
 * sorts on; a key is not a month name. Printed raw, this column said `2028-04` while the
 * period list beside it said "Abril 2028" — the same month, written two ways, in two screens
 * an operator uses to answer the same question.
 */
function owedPeriodsLabel(row: ReceivableRow): string {
    return row.owed_periods.map(etiquetaMes).join(', ');
}
</script>

<template>
    <section class="cdh-content">
        <header class="cdh-page-header">
            <div>
                <h1 class="cdh-page-header__title">Cartera</h1>
                <p class="cdh-page-header__subtitle">
                    {{ total }} {{ total === 1 ? 'cliente con saldo' : 'clientes con saldo' }}
                </p>
            </div>
        </header>

        <AppAlert v-if="error" variant="danger" :title="error" class="mb-4" />

        <div v-if="summary" class="cdh-grid-stats mb-4">
            <article class="cdh-stat">
                <p class="cdh-stat__label">
                    <i class="bi bi-cash-stack" aria-hidden="true" />
                    <span>Saldo total</span>
                </p>
                <p class="cdh-stat__value">{{ pesos(summary.outstanding_balance_cop) }}</p>
                <p class="cdh-stat__hint">{{ summary.clients_with_debt }} clientes</p>
            </article>

            <article class="cdh-stat">
                <p class="cdh-stat__label">
                    <i class="bi bi-exclamation-triangle" aria-hidden="true" />
                    <span>Vencido</span>
                </p>
                <p
                    class="cdh-stat__value"
                    :class="{ 'cdh-danger': summary.overdue_balance_cop > 0 }"
                >
                    {{ pesos(summary.overdue_balance_cop) }}
                </p>
                <p class="cdh-stat__hint">{{ summary.overdue_obligations_count }} obligaciones</p>
            </article>

            <article class="cdh-stat">
                <p class="cdh-stat__label">
                    <i class="bi bi-arrow-left-right" aria-hidden="true" />
                    <span>Anticipos por aplicar</span>
                </p>
                <p class="cdh-stat__value">{{ pesos(summary.unallocated_credit_cop) }}</p>
                <p class="cdh-stat__hint">
                    {{ summary.payments_requiring_reconciliation }} pagos por conciliar
                </p>
            </article>
        </div>

        <div class="cdh-filters" role="search">
            <div class="cdh-filters__field cdh-filters__field--grow">
                <label class="cdh-form-label" for="cartera-search">Buscar</label>
                <input
                    id="cartera-search"
                    v-model="search"
                    class="form-control form-control-sm"
                    type="search"
                    placeholder="Nombre o documento"
                    autocomplete="off"
                />
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="cartera-aging">Antigüedad</label>
                <select id="cartera-aging" v-model="agingBucket" class="form-control form-control-sm">
                    <option value="">Todas</option>
                    <option v-for="option in vocabulary?.aging_buckets ?? []" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="cartera-light">Semáforo</label>
                <select id="cartera-light" v-model="trafficLight" class="form-control form-control-sm">
                    <option value="">Todos</option>
                    <option v-for="option in vocabulary?.traffic_lights ?? []" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="cartera-as-of">Al día de</label>
                <input
                    id="cartera-as-of"
                    v-model="asOf"
                    class="form-control form-control-sm"
                    type="date"
                />
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="cartera-per-page">Por página</label>
                <select
                    id="cartera-per-page"
                    class="form-control form-control-sm"
                    :value="perPage"
                    @change="perPage = Number(($event.target as HTMLSelectElement).value)"
                >
                    <option :value="25">25</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                </select>
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="cartera-overdue">&nbsp;</label>
                <div class="form-check">
                    <input
                        id="cartera-overdue"
                        v-model="overdueOnly"
                        class="form-check-input"
                        type="checkbox"
                    />
                    <label class="form-check-label" for="cartera-overdue">Sólo vencidos</label>
                </div>
            </div>

            <button v-if="isFiltered" type="button" class="cdh-link" @click="clearFilters">
                Limpiar filtros
            </button>
        </div>

        <AppLoading v-if="loading && rows.length === 0" label="Cargando cartera" />

        <AppEmptyState
            v-else-if="!error && rows.length === 0"
            :title="isFiltered ? 'Sin coincidencias' : 'No hay saldos pendientes'"
            :description="
                isFiltered
                    ? 'Pruebe con otro término o quite los filtros.'
                    : 'Ningún cliente debe dinero en este momento.'
            "
            :action-label="isFiltered ? 'Limpiar filtros' : undefined"
            @action="isFiltered && clearFilters()"
        />

        <template v-else>
            <div class="cdh-table-wrap">
                <table class="cdh-table">
                    <caption class="cdh-visually-hidden">
                        Clientes con saldo pendiente
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">Documento</th>
                            <th scope="col">Cliente</th>
                            <th scope="col" class="cdh-table__wide">Empresas</th>
                            <th scope="col">Periodos debidos</th>
                            <th scope="col">Saldo</th>
                            <th scope="col">Vencido</th>
                            <th scope="col">Antigüedad</th>
                            <th scope="col">Semáforo</th>
                            <th scope="col"><span class="cdh-visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.client_id">
                            <td data-label="Documento" class="cdh-table__numeric">
                                {{ row.document_label }}
                            </td>
                            <td data-label="Cliente">
                                <RouterLink
                                    :to="{ name: 'clients.account', params: { id: row.client_id } }"
                                    class="cdh-table__link"
                                >
                                    {{ row.full_name }}
                                </RouterLink>
                            </td>
                            <td data-label="Empresas" class="cdh-table__wide">
                                <span v-if="row.company_names.length > 0">
                                    {{ row.company_names.join(', ') }}
                                </span>
                                <span v-else class="cdh-table__secondary">—</span>
                            </td>
                            <td data-label="Periodos debidos">
                                <!--
                                    Named the way every other screen names a month. The
                                    server sends the key because a key is what it can
                                    aggregate and sort on; printing `2028-04` here while
                                    the period list says "Abril 2028" made one collection
                                    look like a different month from another.
                                -->
                                <span v-if="row.owed_periods.length > 0">
                                    {{ owedPeriodsLabel(row) }}
                                </span>
                                <span v-else class="cdh-table__secondary">—</span>
                            </td>
                            <td data-label="Saldo" class="cdh-table__numeric">
                                {{ pesos(row.balance_cop) }}
                            </td>
                            <td
                                data-label="Vencido"
                                class="cdh-table__numeric"
                                :class="{ 'cdh-danger': row.overdue_balance_cop > 0 }"
                            >
                                {{ row.overdue_balance_cop > 0 ? pesos(row.overdue_balance_cop) : '—' }}
                            </td>
                            <td data-label="Antigüedad">
                                {{ agingLabel(row) }}
                                <span v-if="row.oldest_due_on" class="cdh-table__secondary">
                                    desde {{ fecha(row.oldest_due_on) }}
                                </span>
                            </td>
                            <td data-label="Semáforo">
                                <span
                                    class="cdh-badge"
                                    :class="lightBadge[row.traffic_light] ?? 'cdh-badge--neutral'"
                                >
                                    {{ row.traffic_light_label }}
                                </span>
                                <span class="cdh-table__secondary">
                                    {{ row.traffic_light_reason }}
                                </span>
                            </td>
                            <td data-label="Acciones" class="cdh-table__actions">
                                <RouterLink
                                    :to="{ name: 'clients.account', params: { id: row.client_id } }"
                                    class="cdh-link"
                                >
                                    Ver cuenta
                                </RouterLink>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="lastPage > 1" class="cdh-pagination">
                <AppButton variant="secondary" :disabled="page <= 1" @click="page -= 1">
                    Anterior
                </AppButton>

                <span class="cdh-pagination__label">{{ rangeLabel }}</span>

                <AppButton variant="secondary" :disabled="page >= lastPage" @click="page += 1">
                    Siguiente
                </AppButton>
            </nav>
        </template>
    </section>
</template>
