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

import type { Company, Pagination } from '@/types/api';

const router = useRouter();

/**
 * The company list.
 *
 * Same shape as the client list and for the same reason: the filter is applied on
 * the server and the columns are the ones needed to recognise a company and
 * decide whether to open it.
 */

const auth = useAuthStore();
const canCreate = computed(() => auth.can('companies.create'));
// The client count is relationship data. The server omits the key for a role that
// may not read it, and the column is hidden for the same reason: a `?? 0` here
// would state that the company employs nobody.
const canSeeClients = computed(() => auth.can('relationships.view'));

const search = ref('');
const status = ref('');
const page = ref(1);
const perPage = ref<number>(25);
const settledSearch = useDebouncedRef(search, 300);

const companies = ref<Company[]>([]);
const pagination = ref<Pagination | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

let requestId = 0;

async function load(): Promise<void> {
    const current = ++requestId;

    loading.value = true;
    error.value = null;

    try {
        const payload = await businessApi.companies.list({
            search: settledSearch.value,
            status: status.value,
            per_page: perPage.value,
            page: page.value,
        });

        if (current !== requestId) {
            return;
        }

        companies.value = payload.companies;
        pagination.value = payload.pagination;
    } catch (cause) {
        if (current !== requestId) {
            return;
        }

        companies.value = [];
        error.value = cause instanceof ApiError ? cause.message : 'No fue posible cargar las empresas.';
    } finally {
        if (current === requestId) {
            loading.value = false;
        }
    }
}

watch([settledSearch, status, perPage], () => {
    page.value = 1;
    void load();
});

watch(page, () => void load());

onMounted(load);

function clearFilters(): void {
    search.value = '';
    status.value = '';
}

const isFiltered = computed(() => settledSearch.value !== '' || status.value !== '');
const total = computed(() => pagination.value?.total ?? 0);
const rangeLabel = computed(() => {
    const data = pagination.value;

    return data === null || data.total === 0
        ? 'Sin resultados'
        : `${data.from ?? 0}–${data.to ?? 0} de ${data.total}`;
});
</script>

<template>
    <section class="cdh-content">
        <header class="cdh-page-header">
            <div>
                <h1 class="cdh-page-header__title">Empresas</h1>
                <p class="cdh-page-header__subtitle">
                    {{ total }} {{ total === 1 ? 'empresa registrada' : 'empresas registradas' }}
                </p>
            </div>

            <AppButton v-if="canCreate" icon="bi-plus-lg" :to="{ name: 'companies.create' }"
                >
Nueva empresa
</AppButton
            >
        </header>

        <AppAlert v-if="error" variant="danger" :title="error" class="mb-4" />

        <div class="cdh-filters" role="search">
            <div class="cdh-filters__field cdh-filters__field--grow">
                <label class="cdh-form-label" for="companies-search">Buscar</label>
                <input
                    id="companies-search"
                    v-model="search"
                    class="form-control form-control-sm"
                    type="search"
                    placeholder="Razón social, nombre comercial o NIT"
                    autocomplete="off"
                />
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="companies-status">Estado</label>
                <select id="companies-status" v-model="status" class="form-control form-control-sm">
                    <option value="">Todos</option>
                    <option value="active">Activas</option>
                    <option value="inactive">Inactivas</option>
                </select>
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="companies-per-page">Por página</label>
                <select
                    id="companies-per-page"
                    class="form-control form-control-sm"
                    :value="perPage"
                    @change="perPage = Number(($event.target as HTMLSelectElement).value)"
                >
                    <option :value="25">25</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                </select>
            </div>

            <button v-if="isFiltered" type="button" class="cdh-link" @click="clearFilters">
                Limpiar filtros
            </button>
        </div>

        <AppLoading v-if="loading && companies.length === 0" label="Cargando empresas" />

        <AppEmptyState
            v-else-if="!error && companies.length === 0"
            :title="isFiltered ? 'Sin coincidencias' : 'Aún no hay empresas'"
            :description="
                isFiltered
                    ? 'Pruebe con otro término de búsqueda o quite los filtros.'
                    : 'Registre la primera empresa para poder vincular clientes.'
            "
            :action-label="isFiltered ? 'Limpiar filtros' : canCreate ? 'Nueva empresa' : undefined"
            @action="isFiltered ? clearFilters() : router.push({ name: 'companies.create' })"
        />

        <template v-else>
            <div class="cdh-table-wrap">
                <table class="cdh-table">
                    <caption class="cdh-visually-hidden">{{ rangeLabel }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">Razón social</th>
                            <th scope="col">NIT</th>
                            <th scope="col" class="cdh-table__wide">Contacto</th>
                            <th v-if="canSeeClients" scope="col">Clientes activos</th>
                            <th scope="col">Estado</th>
                            <th scope="col"><span class="cdh-visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="company in companies" :key="company.id">
                            <td data-label="Razón social">
                                <RouterLink
                                    :to="{ name: 'companies.show', params: { id: company.id } }"
                                    class="cdh-table__link"
                                >
                                    {{ company.legal_name }}
                                </RouterLink>
                                <span v-if="company.trade_name" class="cdh-table__secondary">
                                    {{ company.trade_name }}
                                </span>
                            </td>
                            <td data-label="NIT" class="cdh-table__primary cdh-table__numeric">
                                {{ company.tax_id_label ?? '—' }}
                            </td>
                            <td data-label="Contacto" class="cdh-table__contact cdh-table__wide">
                                <span v-if="company.email">{{ company.email }}</span>
                                <span v-if="company.phone" class="cdh-table__secondary">{{ company.phone }}</span>
                                <span v-if="!company.email && !company.phone" class="cdh-table__secondary">—</span>
                            </td>
                            <td v-if="canSeeClients" data-label="Clientes activos">
                                {{ company.active_clients_count ?? 0 }}
                            </td>
                            <td data-label="Estado">
                                <span
                                    class="cdh-badge"
                                    :class="company.status === 'active' ? 'cdh-badge--success' : 'cdh-badge--neutral'"
                                >
                                    {{ company.status_label }}
                                </span>
                            </td>
                            <td data-label="Acciones" class="cdh-table__actions">
                                <RouterLink
                                    :to="{ name: 'companies.show', params: { id: company.id } }"
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
