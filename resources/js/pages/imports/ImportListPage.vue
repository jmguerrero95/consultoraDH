<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { useDebouncedRef } from '@/composables/useDebouncedRef';
import { fecha as formatDate } from '@/composables/useFormatters';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';

import type { ImportStatus, LegacyImport } from '@/types/api';

/**
 * The import list (§17.1).
 *
 * ## The one thing this screen has to get right
 *
 * A row is a file of other people's national identifiers, and the state column is the
 * only thing that says whether looking at the detail screen is safe yet. So the state is
 * spelled out, never reduced to a colour, and the file name is shown next to it rather
 * than on a second screen.
 *
 * ## Polling, and only while it is worth it
 *
 * An upload is answered quickly and parsed in the background (§15), so a fresh import sits
 * in `uploaded`/`parsing` for a while. Polling stops as soon as the state stops moving —
 * an import that reached `review` or `failed` will not change by waiting — which keeps an
 * idle list screen from asking the server a question every few seconds for the rest of the
 * session.
 */

const router = useRouter();

const auth = useAuthStore();

// Only the upload permission is read here. Reviewing and applying happen on the detail screen,
// and pretending this list knows about them would mean two places to keep in step for no gain.
const canCreate = computed(() => auth.can('imports.create'));

const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

const search = ref('');
const status = ref<ImportStatus | ''>('');
const page = ref(1);
const perPage = ref<number>(25);
const settledSearch = useDebouncedRef(search, 300);

const imports = ref<LegacyImport[]>([]);
const pagination = ref<{ current_page: number; last_page: number; total: number } | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

const file = ref<File | null>(null);
const uploading = ref(false);
const uploadError = ref<string | null>(null);

let requestId = 0;
let pollTimer: ReturnType<typeof setTimeout> | null = null;

async function load(): Promise<void> {
    const current = ++requestId;

    loading.value = true;
    error.value = null;

    try {
        const payload = await businessApi.imports.list({
            search: settledSearch.value,
            status: status.value,
            per_page: perPage.value,
            page: page.value,
        });

        if (current !== requestId) {
            return;
        }

        imports.value = payload.data;
        pagination.value = payload.meta;
    } catch (cause) {
        if (current !== requestId) {
            return;
        }

        imports.value = [];
        error.value = cause instanceof ApiError ? cause.message : 'No fue posible cargar las importaciones.';
    } finally {
        if (current === requestId) {
            loading.value = false;
        }
    }
}

function isPending(row: LegacyImport): boolean {
    return ['uploaded', 'queued', 'parsing', 'applying'].includes(row.status);
}

function schedulePoll(): void {
    if (pollTimer !== null) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }

    // Nothing in flight means nothing to wait for.
    if (!imports.value.some(isPending)) {
        return;
    }

    pollTimer = setTimeout(() => void load(), 3000);
}

/**
 * §17.2's local check, before the file is sent anywhere.
 *
 * The extension and the size are both checked again on the server — the guard reads the
 * actual OpenXML structure, not the name — but an operator who picks the wrong file should
 * find out before a 700 KB upload rather than after it.
 */
function chooseFile(event: Event): void {
    uploadError.value = null;

    const input = event.target as HTMLInputElement;
    const chosen = input.files?.[0] ?? null;

    if (chosen === null) {
        file.value = null;

        return;
    }

    if (!chosen.name.toLowerCase().endsWith('.xlsx')) {
        uploadError.value = 'Sólo se aceptan archivos .xlsx.';
        file.value = null;
        input.value = '';

        return;
    }

    if (chosen.size > MAX_UPLOAD_BYTES) {
        uploadError.value = `El archivo pesa ${(chosen.size / 1024 / 1024).toFixed(1)} MB y el máximo es 10 MB.`;
        file.value = null;
        input.value = '';

        return;
    }

    file.value = chosen;
}

async function upload(): Promise<void> {
    if (file.value === null) {
        return;
    }

    uploading.value = true;
    uploadError.value = null;

    try {
        const payload = await businessApi.imports.upload(file.value);

        await router.push({ name: 'imports.show', params: { id: payload.data.id } });
    } catch (cause) {
        uploadError.value = cause instanceof ApiError ? cause.message : 'No fue posible subir el archivo.';
    } finally {
        uploading.value = false;
    }
}

watch([settledSearch, status, perPage], () => {
    page.value = 1;
    void load();
});

watch(page, () => void load());

watch(imports, schedulePoll, { deep: true });

onMounted(load);

function clearFilters(): void {
    search.value = '';
    status.value = '';
}

const isFiltered = computed(() => settledSearch.value !== '' || status.value !== '');
const total = computed(() => pagination.value?.total ?? 0);

/** §17.1 asks for these columns; nothing here can leak a value from the workbook. */
function blockersOf(row: LegacyImport): number {
    return row.summary?.unresolved_blockers ?? row.summary?.blocking_issues ?? 0;
}

function appliedCount(row: LegacyImport): string {
    const applied = row.summary?.applied;

    if (applied === undefined) {
        return '—';
    }

    return String(Object.values(applied).reduce((sum, value) => sum + value, 0));
}
</script>

<template>
    <section class="cdh-content">
        <header class="cdh-page-header">
            <div>
                <h1 class="cdh-page-header__title">Importaciones</h1>
                <p class="cdh-page-header__subtitle">
                    {{ total }} {{ total === 1 ? 'importación registrada' : 'importaciones registradas' }}
                </p>
            </div>
        </header>

        <AppAlert v-if="error" variant="danger" :title="error" class="mb-4" />

        <!--
            §17.2: the explanation that this changes nothing comes before the button, not
            after it. Somebody about to hand over a payroll file should read that first.
        -->
        <section v-if="canCreate" class="cdh-card mb-4" aria-labelledby="imports-upload-title">
            <div class="cdh-card__body">
                <h2 id="imports-upload-title" class="cdh-card__title">Subir un libro de Excel</h2>
                <p class="cdh-text-muted mb-3">
                    El archivo se analiza primero y <strong>no modifica ningún dato</strong>. Se leen
                    las personas, sus afiliaciones y sus valores, y se prepara un plan que usted revisa
                    antes de aplicar. El archivo original queda en un almacenamiento privado y las
                    contraseñas que el libro contenga se ocultan en todo lo que se muestra.
                </p>

                <div class="cdh-form-field mb-3">
                    <label class="cdh-form-label" for="imports-file">Archivo .xlsx</label>
                    <input
                        id="imports-file"
                        class="form-control form-control-sm"
                        type="file"
                        accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        @change="chooseFile"
                    />
                    <p class="cdh-form-hint">Tamaño máximo 10 MB. Se acepta un libro por mes y empresa.</p>
                </div>

                <AppAlert v-if="uploadError" variant="danger" :title="uploadError" class="mb-3" />

                <AppButton :disabled="file === null || uploading" :busy="uploading" @click="upload">
                    Subir y analizar
                </AppButton>
            </div>
        </section>

        <div class="cdh-filters" role="search">
            <div class="cdh-filters__field cdh-filters__field--grow">
                <label class="cdh-form-label" for="imports-search">Buscar</label>
                <input
                    id="imports-search"
                    v-model="search"
                    class="form-control form-control-sm"
                    type="search"
                    placeholder="Nombre del archivo o identificador"
                    autocomplete="off"
                />
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="imports-status">Estado</label>
                <select id="imports-status" v-model="status" class="form-control form-control-sm">
                    <option value="">Todos</option>
                    <option value="uploaded">Subida</option>
                    <option value="parsing">Analizando</option>
                    <option value="review">En revisión</option>
                    <option value="ready">Lista para aplicar</option>
                    <option value="applying">Aplicando</option>
                    <option value="applied">Aplicada</option>
                    <option value="failed">Con errores</option>
                    <option value="cancelled">Cancelada</option>
                </select>
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="imports-per-page">Por página</label>
                <select
                    id="imports-per-page"
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

        <AppLoading v-if="loading && imports.length === 0" label="Cargando importaciones" />

        <AppEmptyState
            v-else-if="!error && imports.length === 0"
            :title="isFiltered ? 'Sin coincidencias' : 'Aún no hay importaciones'"
            :description="
                isFiltered
                    ? 'Pruebe con otro término de búsqueda o quite los filtros.'
                    : 'Suba un libro de Excel para reconstruir la historia de clientes y empresas.'
            "
            :action-label="isFiltered ? 'Limpiar filtros' : undefined"
            @action="clearFilters"
        />

        <template v-else>
            <div class="cdh-table-wrap">
                <table class="cdh-table">
                    <caption class="cdh-visually-hidden">Importaciones de libros de Excel</caption>
                    <thead>
                        <tr>
                            <th scope="col">Archivo</th>
                            <th scope="col">Estado</th>
                            <th scope="col">Filas</th>
                            <th scope="col">Bloqueos</th>
                            <th scope="col">Fecha</th>
                            <th scope="col">Usuario</th>
                            <th scope="col">Aplicadas</th>
                            <th scope="col"><span class="cdh-visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in imports" :key="row.id">
                            <th scope="row" class="cdh-table__primary">{{ row.original_filename }}</th>
                            <td>
                                <span class="cdh-badge" :class="`cdh-badge--${row.status}`">
                                    {{ row.status_label }}
                                </span>
                            </td>
                            <td>{{ row.summary?.rows ?? '—' }}</td>
                            <td>
                                <span v-if="blockersOf(row) > 0" class="cdh-badge cdh-badge--error">
                                    {{ blockersOf(row) }}
                                </span>
                                <span v-else class="cdh-text-muted">0</span>
                            </td>
                            <td>{{ formatDate(row.created_at) }}</td>
                            <td>{{ row.created_by ?? '—' }}</td>
                            <td>{{ appliedCount(row) }}</td>
                            <td>
                                <RouterLink
                                    class="cdh-link"
                                    :to="{ name: 'imports.show', params: { id: row.id } }"
                                >
                                    Ver
                                </RouterLink>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="pagination && pagination.last_page > 1" class="cdh-pagination">
                <AppButton variant="secondary" :disabled="pagination.current_page <= 1" @click="page -= 1">
                    Anterior
                </AppButton>
                <span class="cdh-pagination__label">
                    Página {{ pagination.current_page }} de {{ pagination.last_page }} · {{ total }}
                </span>
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
