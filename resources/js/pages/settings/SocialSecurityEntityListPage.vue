<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppFormField from '@/components/ui/AppFormField.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import AppModal from '@/components/ui/AppModal.vue';
import { useDebouncedRef } from '@/composables/useDebouncedRef';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

import type { Pagination, SocialSecurityEntity, SocialSecurityType } from '@/types/api';

/**
 * The catalogue of EPS, AFP, ARL and Cajas de Compensación Familiar.
 *
 * It ships empty, and that is a decision rather than an omission. A list of every
 * entity in the country written by hand would be stale the day it was written and
 * would look exactly as trustworthy as one checked against an official source.
 * An administrator with the permission fills it from a source they trust.
 *
 * The type label is spelled out rather than shown as an acronym, because someone
 * looking for a Caja should find "Caja de Compensación Familiar".
 */

const auth = useAuthStore();
const toast = useToastStore();

const canManage = computed(() => auth.can('social_security_entities.manage'));
// Same reasoning as the client counts: how many people are affiliated to an entity
// is affiliation data, so the column exists only for a role that may read it.
const canSeeAffiliations = computed(() => auth.can('affiliations.view'));

const TYPES: { value: SocialSecurityType; label: string }[] = [
    { value: 'EPS', label: 'EPS' },
    { value: 'AFP', label: 'AFP' },
    { value: 'ARL', label: 'ARL' },
    { value: 'CCF', label: 'Caja de Compensación Familiar' },
];

const search = ref('');
const status = ref('');
const type = ref('');
const page = ref(1);
const perPage = ref<number>(25);
const settledSearch = useDebouncedRef(search, 300);

const entities = ref<SocialSecurityEntity[]>([]);
const pagination = ref<Pagination | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

let requestId = 0;

async function load(): Promise<void> {
    const current = ++requestId;

    loading.value = true;
    error.value = null;

    try {
        const payload = await businessApi.entities.list({
            search: settledSearch.value,
            status: status.value,
            type: type.value,
            per_page: perPage.value,
            page: page.value,
        });

        if (current !== requestId) {
            return;
        }

        entities.value = payload.entities;
        pagination.value = payload.pagination;
    } catch (cause) {
        if (current !== requestId) {
            return;
        }

        entities.value = [];
        error.value = cause instanceof ApiError ? cause.message : 'No fue posible cargar el catálogo.';
    } finally {
        if (current === requestId) {
            loading.value = false;
        }
    }
}

watch([settledSearch, status, type, perPage], () => {
    page.value = 1;
    void load();
});

watch(page, () => void load());

onMounted(load);

function clearFilters(): void {
    search.value = '';
    status.value = '';
    type.value = '';
}

const isFiltered = computed(
    () => settledSearch.value !== '' || status.value !== '' || type.value !== '',
);

const total = computed(() => pagination.value?.total ?? 0);

const rangeLabel = computed(() => {
    const data = pagination.value;

    return data === null || data.total === 0
        ? 'Sin resultados'
        : `${data.from ?? 0}–${data.to ?? 0} de ${data.total}`;
});

// --- Create and edit ---------------------------------------------------------

const formOpen = ref(false);
const editing = ref<SocialSecurityEntity | null>(null);
const submitting = ref(false);
const formError = ref<string | null>(null);
const fieldErrors = reactive<Record<string, string | null>>({});

const form = reactive({ type: 'EPS' as SocialSecurityType, name: '', code: '', tax_id: '' });

function openCreate(): void {
    editing.value = null;
    form.type = 'EPS';
    form.name = '';
    form.code = '';
    form.tax_id = '';
    formError.value = null;
    clearFieldErrors();

    formOpen.value = true;
}

function openEdit(entity: SocialSecurityEntity): void {
    editing.value = entity;
    form.type = entity.type;
    form.name = entity.name;
    form.code = entity.code ?? '';
    form.tax_id = entity.tax_id ?? '';
    formError.value = null;
    clearFieldErrors();

    formOpen.value = true;
}

function clearFieldErrors(): void {
    for (const key of Object.keys(fieldErrors)) {
        delete fieldErrors[key];
    }
}

async function submit(): Promise<void> {
    if (submitting.value) {
        return;
    }

    submitting.value = true;
    formError.value = null;
    clearFieldErrors();

    const payload: Record<string, string | null> = {
        name: form.name,
        code: form.code === '' ? null : form.code,
        tax_id: form.tax_id === '' ? null : form.tax_id,
    };

    try {
        if (editing.value === null) {
            payload.type = form.type;
            await businessApi.entities.create(payload);
            toast.success('Entidad registrada.');
        } else {
            await businessApi.entities.update(editing.value.id, payload);
            toast.success('Entidad actualizada.');
        }

        formOpen.value = false;

        await load();
    } catch (cause) {
        if (cause instanceof ApiError) {
            for (const [field, messages] of Object.entries(cause.errors)) {
                fieldErrors[field] = messages[0] ?? null;
            }

            formError.value = cause.message;
        } else {
            formError.value = 'No fue posible guardar la entidad.';
        }
    } finally {
        submitting.value = false;
    }
}

// --- Deactivate --------------------------------------------------------------

const deactivateTarget = ref<SocialSecurityEntity | null>(null);
const busy = ref(false);

async function confirmDeactivate(): Promise<void> {
    if (deactivateTarget.value === null) {
        return;
    }

    busy.value = true;

    try {
        await businessApi.entities.deactivate(deactivateTarget.value.id);

        deactivateTarget.value = null;
        toast.success('Entidad desactivada.');

        await load();
    } catch (cause) {
        if (cause instanceof ApiError) {
            toast.error(cause.message);
        } else {
            toast.error('No fue posible desactivar la entidad.');
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="cdh-content">
        <header class="cdh-page-header">
            <div>
                <h1 class="cdh-page-header__title">Entidades de seguridad social</h1>
                <p class="cdh-page-header__subtitle">
                    {{ total }} {{ total === 1 ? 'entidad registrada' : 'entidades registradas' }}
                </p>
            </div>

            <AppButton v-if="canManage" icon="bi-plus-lg" @click="openCreate">Nueva entidad</AppButton>
        </header>

        <AppAlert
            v-if="!canManage"
            variant="info"
            title="Consulta de solo lectura. Su rol no permite crear ni modificar entidades."
            class="mb-4"
        />

        <AppAlert v-if="error" variant="danger" :title="error" class="mb-4" />

        <div class="cdh-filters" role="search">
            <div class="cdh-filters__field cdh-filters__field--grow">
                <label class="cdh-form-label" for="entities-search">Buscar</label>
                <input
                    id="entities-search"
                    v-model="search"
                    class="form-control form-control-sm"
                    type="search"
                    placeholder="Nombre o código"
                    autocomplete="off"
                />
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="entities-type">Tipo</label>
                <select id="entities-type" v-model="type" class="form-control form-control-sm">
                    <option value="">Todos</option>
                    <option v-for="option in TYPES" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="entities-status">Estado</label>
                <select id="entities-status" v-model="status" class="form-control form-control-sm">
                    <option value="">Todos</option>
                    <option value="active">Activas</option>
                    <option value="inactive">Inactivas</option>
                </select>
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="entities-per-page">Por página</label>
                <select
                    id="entities-per-page"
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

        <AppLoading v-if="loading && entities.length === 0" label="Cargando catálogo" />

        <AppEmptyState
            v-else-if="!error && entities.length === 0"
            :title="isFiltered ? 'Sin coincidencias' : 'El catálogo está vacío'"
            :description="
                isFiltered
                    ? 'Pruebe con otro término de búsqueda o quite los filtros.'
                    : 'Registre las entidades que ADMINISTRADOR use. No se incluye un catálogo'
                      + ' predeterminado porque una lista escrita de memoria se vuelve obsoleta'
                      + ' sin que nada lo indique.'
            "
            :action-label="isFiltered ? 'Limpiar filtros' : canManage ? 'Nueva entidad' : undefined"
            @action="isFiltered ? clearFilters() : openCreate()"
        />

        <template v-else>
            <div class="cdh-table-wrap">
                <table class="cdh-table">
                    <caption class="cdh-visually-hidden">{{ rangeLabel }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">Tipo</th>
                            <th scope="col">Nombre</th>
                            <th scope="col">Código</th>
                            <th v-if="canSeeAffiliations" scope="col">Afiliaciones activas</th>
                            <th scope="col">Estado</th>
                            <th scope="col"><span class="cdh-visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entity in entities" :key="entity.id">
                            <td data-label="Tipo">
                                <span class="cdh-badge cdh-badge--info">{{ entity.type_label }}</span>
                            </td>
                            <td data-label="Nombre" class="cdh-table__primary">{{ entity.name }}</td>
                            <td data-label="Código">{{ entity.code ?? '—' }}</td>
                            <td v-if="canSeeAffiliations" data-label="Afiliaciones activas">
                                {{ entity.active_affiliations_count ?? 0 }}
                            </td>
                            <td data-label="Estado">
                                <span
                                    class="cdh-badge"
                                    :class="entity.status === 'active' ? 'cdh-badge--success' : 'cdh-badge--neutral'"
                                >
                                    {{ entity.status_label }}
                                </span>
                            </td>
                            <td v-if="canManage" data-label="Acciones" class="cdh-table__actions">
                                <button type="button" class="cdh-link" @click="openEdit(entity)">Editar</button>
                                <button
                                    v-if="entity.status === 'active'"
                                    type="button"
                                    class="cdh-link"
                                    @click="deactivateTarget = entity"
                                >
                                    Desactivar
                                </button>
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

        <AppModal
            :open="formOpen"
            :title="editing === null ? 'Nueva entidad' : 'Editar entidad'"
            :confirm-label="editing === null ? 'Registrar' : 'Guardar cambios'"
            :busy="submitting"
            @confirm="submit"
            @cancel="formOpen = false"
        >
            <AppAlert v-if="formError" variant="danger" :title="formError" class="mb-3" />

            <AppFormField
                v-model="form.type"
                label="Tipo"
                name="entity_type"
                required
                :options="TYPES"
                :disabled="editing !== null"
                :error="fieldErrors.type ?? null"
            />

            <AppFormField
                v-model="form.name"
                label="Nombre"
                name="entity_name"
                required
                :error="fieldErrors.name ?? null"
                hint="Dos entidades del mismo tipo no pueden compartir nombre."
            />

            <AppFormField
                v-model="form.code"
                label="Código"
                name="entity_code"
                :error="fieldErrors.code ?? null"
            />

            <AppFormField
                v-model="form.tax_id"
                label="NIT"
                name="entity_tax_id"
                :error="fieldErrors.tax_id ?? null"
            />
        </AppModal>

        <AppModal
            :open="deactivateTarget !== null"
            title="Desactivar entidad"
            confirm-label="Desactivar"
            destructive
            :busy="busy"
            @confirm="confirmDeactivate"
            @cancel="deactivateTarget = null"
        >
            <p>
                <strong>{{ deactivateTarget?.name }}</strong> dejará de aparecer al registrar afiliaciones. Las
                afiliaciones de años anteriores se conservan y siguen resolviendo a esta entidad, por lo que
                la operación puede revertirse.
            </p>
            <p class="cdh-text-sm cdh-text-muted mb-0">
                No es posible desactivar una entidad que todavía tenga afiliaciones abiertas.
            </p>
        </AppModal>
    </section>
</template>
