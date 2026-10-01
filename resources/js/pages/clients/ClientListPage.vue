<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRouter } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { useDebouncedRef } from '@/composables/useDebouncedRef';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';

import type { ClientListItem, CompanySummary, Pagination } from '@/types/api';

const router = useRouter();

/**
 * The client portfolio.
 *
 * Everything on this screen is filtered on the server. A portfolio that grows to
 * thousands of people cannot be searched in the browser: it would mean shipping
 * every record to render a list of twenty five, and the person typing in the
 * search box would wait for a transfer nobody needs.
 *
 * The columns are deliberately few. The document, the name, the companies they
 * are currently at, how to reach them and the status: what an administrator
 * needs to recognise a row and decide whether to open it.
 */

const auth = useAuthStore();

const canCreate = computed(() => auth.can('clients.create'));

// --- Filters -----------------------------------------------------------------
const search = ref('');
const status = ref('');
const companyId = ref('');
const page = ref(1);
// Number, because it is sent straight back as a query parameter.
const perPage = ref<number>(25);

// The search box drives a debounced copy, so a request is sent when the typing
// stops rather than on every keystroke.
const settledSearch = useDebouncedRef(search, 300);

// --- Results -----------------------------------------------------------------
const clients = ref<ClientListItem[]>([]);
const pagination = ref<Pagination | null>(null);
const companies = ref<CompanySummary[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

/** Bumped on every load so a slow response cannot overwrite a newer one. */
let requestId = 0;

async function load(): Promise<void> {
    const current = ++requestId;

    loading.value = true;
    error.value = null;

    try {
        const payload = await businessApi.clients.list({
            search: settledSearch.value,
            status: status.value,
            // The select yields a string; the filter expects an id.
            company_id: companyId.value === '' ? null : Number(companyId.value),
            per_page: perPage.value,
            page: page.value,
        });

        // A response that arrived after a newer request started is stale.
        if (current !== requestId) {
            return;
        }

        clients.value = payload.clients;
        pagination.value = payload.pagination;
    } catch (cause) {
        if (current !== requestId) {
            return;
        }

        clients.value = [];
        error.value =
            cause instanceof ApiError
                ? cause.message
                : 'No fue posible cargar los clientes.';
    } finally {
        if (current === requestId) {
            loading.value = false;
        }
    }
}

/** Any filter change starts again from the first page. */
watch([settledSearch, status, companyId, perPage], () => {
    page.value = 1;
    void load();
});

watch(page, () => void load());

async function loadCompanies(): Promise<void> {
    try {
        companies.value = (await businessApi.clients.companyOptions()).companies;
    } catch {
        // The filter is a convenience: if it cannot be filled the list still
        // works, and failing the whole screen over an empty select would be
        // the wrong trade.
        companies.value = [];
    }
}

onMounted(() => {
    void load();
    void loadCompanies();
});

function clearFilters(): void {
    search.value = '';
    status.value = '';
    companyId.value = '';
}

const isFiltered = computed(
    () => settledSearch.value !== '' || status.value !== '' || companyId.value !== '',
);

const total = computed(() => pagination.value?.total ?? 0);

const rangeLabel = computed(() => {
    const data = pagination.value;

    if (data === null || data.total === 0) {
        return 'Sin resultados';
    }

    return `${data.from ?? 0}–${data.to ?? 0} de ${data.total}`;
});

/**
 * The companies a row shows. Falls back to the count so the column is never
 * empty when the server did not send the relations.
 */
function companyNames(client: ClientListItem): string {
    if (client.companies.length > 0) {
        return client.companies.map((company) => company.display_name).join(', ');
    }

    return client.companies_count === null ? '—' : `${client.companies_count}`;
}
</script>

<template>
    <section class="cdh-content">
        <header class="cdh-page-header">
            <div>
                <h1 class="cdh-page-header__title">Clientes</h1>
                <p class="cdh-page-header__subtitle">
                    {{ total }} {{ total === 1 ? 'cliente registrado' : 'clientes registrados' }}
                </p>
            </div>

            <AppButton v-if="canCreate" icon="bi-plus-lg" :to="{ name: 'clients.create' }"
                >
Nuevo cliente
</AppButton
            >
        </header>

        <AppAlert v-if="error" variant="danger" :title="error" class="mb-4" />

        <div class="cdh-filters" role="search">
            <div class="cdh-filters__field cdh-filters__field--grow">
                <label class="cdh-form-label" for="clients-search">Buscar</label>
                <input
                    id="clients-search"
                    v-model="search"
                    class="form-control form-control-sm"
                    type="search"
                    placeholder="Documento, nombre, correo o teléfono"
                    autocomplete="off"
                />
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="clients-status">Estado</label>
                <select id="clients-status" v-model="status" class="form-control form-control-sm">
                    <option value="">Todos</option>
                    <option value="active">Activos</option>
                    <option value="inactive">Inactivos</option>
                </select>
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="clients-company">Empresa</label>
                <select id="clients-company" v-model="companyId" class="form-control form-control-sm">
                    <option value="">Todas</option>
                    <option v-for="company in companies" :key="company.id" :value="company.id">
                        {{ company.display_name }}
                    </option>
                </select>
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="clients-per-page">Por página</label>
                <select
                    id="clients-per-page"
                    class="form-control form-control-sm"
                    :value="perPage"
                    @change="perPage = Number(($event.target as HTMLSelectElement).value)"
                >
                    <option :value="25">25</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                </select>
            </div>

            <button
                v-if="isFiltered"
                type="button"
                class="cdh-link"
                @click="clearFilters"
            >
                Limpiar filtros
            </button>
        </div>

        <AppLoading v-if="loading && clients.length === 0" label="Cargando clientes" />

        <AppEmptyState
            v-else-if="!error && clients.length === 0"
            :title="isFiltered ? 'Sin coincidencias' : 'Aún no hay clientes'"
            :description="
                isFiltered
                    ? 'Pruebe con otro término de búsqueda o quite los filtros.'
                    : 'Registre el primer cliente para empezar a construir el portafolio.'
            "
            :action-label="isFiltered ? 'Limpiar filtros' : canCreate ? 'Nuevo cliente' : undefined"
            :action-to="isFiltered ? undefined : { name: 'clients.create' }"
            @action="isFiltered ? clearFilters() : router.push({ name: 'clients.create' })"
        />

        <template v-else>
            <div class="cdh-table-wrap">
                <table class="cdh-table">
                    <caption class="cdh-visually-hidden">
                        {{ rangeLabel }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">Documento</th>
                            <th scope="col">Cliente</th>
                            <th scope="col" class="cdh-table__wide">Empresa(s) activa(s)</th>
                            <th scope="col">Contacto</th>
                            <th scope="col">Estado</th>
                            <th scope="col"><span class="cdh-visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="client in clients" :key="client.id">
                            <td data-label="Documento">
                                <span class="cdh-table__primary cdh-table__numeric">{{ client.document_label }}</span>
                            </td>
                            <td data-label="Cliente">
                                <RouterLink
                                    :to="{ name: 'clients.show', params: { id: client.id } }"
                                    class="cdh-table__link"
                                >
                                    {{ client.full_name }}
                                </RouterLink>
                            </td>
                            <td data-label="Empresa(s) activa(s)" class="cdh-table__wide">
                                {{ companyNames(client) }}
                            </td>
                            <td data-label="Contacto" class="cdh-table__contact">
                                <span v-if="client.email">{{ client.email }}</span>
                                <span v-if="client.phone" class="cdh-table__secondary">
                                    {{ client.phone }}
                                </span>
                                <span v-if="!client.email && !client.phone" class="cdh-table__secondary">
                                    —
                                </span>
                            </td>
                            <td data-label="Estado">
                                <span
                                    class="cdh-badge"
                                    :class="client.status === 'active' ? 'cdh-badge--success' : 'cdh-badge--neutral'"
                                >
                                    {{ client.status_label }}
                                </span>
                            </td>
                            <td data-label="Acciones" class="cdh-table__actions">
                                <RouterLink
                                    :to="{ name: 'clients.show', params: { id: client.id } }"
                                    class="cdh-link"
                                >
                                    Ver ficha
                                </RouterLink>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="pagination && pagination.last_page > 1" class="cdh-pagination">
                <AppButton
                    variant="secondary"
                    :disabled="pagination.current_page <= 1"
                    @click="page -= 1"
                >
                    Anterior
                </AppButton>

                <span class="cdh-pagination__label">{{ rangeLabel }}</span>

                <AppButton
                    variant="secondary"
                    :disabled="pagination.current_page >= pagination.last_page"
                    @click="page += 1"
                >
                    Siguiente
                </AppButton>
            </nav>
        </template>
    </section>
</template>