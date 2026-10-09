<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { useAuthStore } from '@/stores/auth';
import { a05 } from '@/services/a05';
import { ApiError } from '@/services/http';

import type { ReportPayload, VocabularyOption } from '@/types/api';

/**
 * Reportes.
 *
 * §51/§53: la pantalla y cada descarga usan **el mismo objeto de filtros**. El PDF,
 * el CSV y el XLSX describen la población que está en pantalla, no "la cartera
 * entera", porque la URL de descarga se construye con los mismos filtros que la tabla.
 *
 * §54: el botón de PDF es el «descargar PDF de morosos» histórico, y usa los filtros
 * aplicados en este momento.
 */

const auth = useAuthStore();

const types = ref<VocabularyOption[]>([]);
const formats = ref<VocabularyOption[]>([]);

const tipo = ref('morosos');
const filtros = ref<Record<string, string>>({
    as_of: '',
    company_id: '',
    search: '',
    aging_bucket: '',
    traffic_light: '',
    entity_type: '',
    operator: '',
    status: '',
});

const reporte = ref<ReportPayload | null>(null);
const cargando = ref(true);
const errorMessage = ref<string | null>(null);

const puedeExportar = computed(() => auth.can('reports.export'));

const filtroLimpio = computed(() => {
    const limpio: Record<string, string> = {};

    for (const [clave, valor] of Object.entries(filtros.value)) {
        if (valor !== '') {
            limpio[clave] = valor;
        }
    }

    return limpio;
});

function urlDescarga(format: string): string {
    const params = new URLSearchParams({ type: tipo.value, format, ...filtroLimpio.value });

    return `/api/reports/download?${params.toString()}`;
}

/** §54: the download uses exactly the filters the table was loaded with. */
function descargar(format: string): void {
    window.open(urlDescarga(format), '_blank', 'noopener');
}

async function cargar(): Promise<void> {
    cargando.value = true;
    errorMessage.value = null;

    try {
        reporte.value = await a05.reports.run(tipo.value, filtroLimpio.value);
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo generar el reporte.';
    } finally {
        cargando.value = false;
    }
}

onMounted(async () => {
    try {
        const vocabulario = await a05.reports.vocabulary();

        types.value = vocabulario.types;
        formats.value = vocabulario.formats;
    } catch {
        errorMessage.value = 'No se pudo cargar el catálogo de reportes.';
    }

    await cargar();
});
</script>

<template>
    <div class="container-fluid py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0">Reportes</h1>

            <div v-if="puedeExportar" class="d-flex gap-2">
                <AppButton variant="ghost" @click="descargar('xlsx')">
                    XLSX
                </AppButton>
                <AppButton variant="ghost" @click="descargar('csv')">
                    CSV
                </AppButton>
                <AppButton variant="primary" @click="descargar('pdf')">
                    <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true" />
                    Descargar PDF de morosos
                </AppButton>
            </div>
        </div>

        <AppAlert v-if="errorMessage" variant="danger" :message="errorMessage" class="mb-3" />

        <form class="card mb-3" @submit.prevent="cargar">
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="tipo">Reporte</label>
                        <select id="tipo" v-model="tipo" class="form-select" @change="cargar">
                            <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="as-of">Corte</label>
                        <input id="as-of" v-model="filtros.as_of" type="date" class="form-control" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="empresa">Empresa (id)</label>
                        <input
                            id="empresa"
                            v-model="filtros.company_id"
                            type="number"
                            class="form-control"
                        />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="buscar">Buscar cliente</label>
                        <input id="buscar" v-model="filtros.search" type="search" class="form-control" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="operador">Operador</label>
                        <input id="operador" v-model="filtros.operator" class="form-control" />
                    </div>
                    <div class="col-12 col-md-1">
                        <AppButton type="submit" variant="primary" :loading="cargando">Aplicar</AppButton>
                    </div>
                </div>
            </div>
        </form>

        <AppLoading v-if="cargando" />

        <template v-else-if="reporte">
            <div class="card mb-3">
                <div class="card-body py-2">
                    <strong>{{ reporte.meta.title }}</strong>
                    <span class="text-body-secondary ms-2">
                        corte {{ reporte.meta.as_of }} · generado {{ reporte.meta.generated_at }}
                    </span>
                    <div v-if="reporte.meta.filters.length > 0" class="small text-body-secondary">
                        Filtros: {{ reporte.meta.filters.join(' · ') }}
                    </div>
                </div>
            </div>

            <AppEmptyState
                v-if="reporte.rows.length === 0"
                title="Sin resultados"
                description="Ninguna fila coincide con los filtros aplicados."
            />

            <div v-else class="card">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <caption class="visually-hidden">{{ reporte.meta.title }}</caption>
                        <thead>
                            <tr>
                                <th
                                    v-for="columna in reporte.columns"
                                    :key="columna.key"
                                    scope="col"
                                    :class="{ 'text-end': columna.type === 'money' }"
                                >
                                    {{ columna.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(fila, indice) in reporte.rows" :key="indice">
                                <td
                                    v-for="columna in reporte.columns"
                                    :key="columna.key"
                                    :class="{ 'text-end': columna.type === 'money' }"
                                >
                                    {{ fila[columna.key] ?? '—' }}
                                </td>
                            </tr>
                            <tr v-for="(total, indice) in reporte.totals" :key="`t-${indice}`" class="fw-bold">
                                <td
                                    v-for="columna in reporte.columns"
                                    :key="columna.key"
                                    :class="{ 'text-end': columna.type === 'money' }"
                                >
                                    {{ total[columna.key] ?? '' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">
                    <small class="text-body-secondary">{{ reporte.meta.disclaimer }}</small>
                </div>
            </div>
        </template>
    </div>
</template>