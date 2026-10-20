<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { useAuthStore } from '@/stores/auth';
import { a05 } from '@/services/a05';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';

import type { CompanySummary, ReportPayload, VocabularyOption } from '@/types/api';

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

// --- FIX-EXPORT-IMPORT-03 Block C: the operational workbook -------------------------
//
// Its own state, not a filter on the report above. The two describe different things — the
// table is a population on screen, the workbook is a set of months and employers — and
// sharing one filter object is how "Descargue PDF de morosos" ends up asking for a
// workbook's months.

const MESES = [
    { valor: 1, etiqueta: 'Enero' },
    { valor: 2, etiqueta: 'Febrero' },
    { valor: 3, etiqueta: 'Marzo' },
    { valor: 4, etiqueta: 'Abril' },
    { valor: 5, etiqueta: 'Mayo' },
    { valor: 6, etiqueta: 'Junio' },
    { valor: 7, etiqueta: 'Julio' },
    { valor: 8, etiqueta: 'Agosto' },
    { valor: 9, etiqueta: 'Septiembre' },
    { valor: 10, etiqueta: 'Octubre' },
    { valor: 11, etiqueta: 'Noviembre' },
    { valor: 12, etiqueta: 'Diciembre' },
];

/** How far back the year list goes, and one year forward. */
const aniosLibro = computed(() => {
    const actual = new Date().getFullYear();
    const lista: number[] = [];

    for (let a = actual + 1; a >= actual - 8; a--) {
        lista.push(a);
    }

    return lista;
});

const libro = ref({
    anio: new Date().getFullYear(),
    desde: 1,
    hasta: 12,
    empresa: '',
});

const empresasLibro = ref<CompanySummary[]>([]);
const descargandoLibro = ref(false);
const errorLibro = ref<string | null>(null);

/**
 * The colour legend, shown next to the control.
 *
 * C.3 requires the legend in the interface and the point of it is that the colours are
 * **computed from the financial records**, not copied from the historical file. So each row
 * says what the system had to know to produce it — "una obligación con saldo vencido" rather
 * than a bare swatch.
 */
const LEYENDA_LIBRO = [
    { color: 'cdh-leyenda__muestra--verde', texto: 'Verde — pagado', detalle: 'Obligación cubierta por pagos aplicados.' },
    { color: 'cdh-leyenda__muestra--amarillo', texto: 'Amarillo — pendiente', detalle: 'Saldo por pagar que aún no vence.' },
    { color: 'cdh-leyenda__muestra--rojo', texto: 'Rojo — vencido', detalle: 'Saldo pendiente después de la fecha de vencimiento.' },
    { color: 'cdh-leyenda__muestra--rojo', texto: 'Rojo + RETIRADO', detalle: 'La relación laboral terminó en ese mes. El color sigue siendo el del pago.' },
    { color: '', texto: 'Sin color', detalle: 'Sin información de pago: no hay obligación registrada para ese mes.' },
];

function urlLibro(): string {
    const meses = libro.value.hasta >= libro.value.desde
        ? `${libro.value.desde}-${libro.value.hasta}`
        : `${libro.value.hasta}`;

    const params = new URLSearchParams({
        type: 'libro_operativo',
        format: 'xlsx',
        year: String(libro.value.anio),
        months: meses,
    });

    if (libro.value.empresa !== '') {
        params.set('company_id', libro.value.empresa);
    }

    return `/api/reports/download?${params.toString()}`;
}

/** A download in progress, by format or by workbook. */
const descargandoFormato = ref<string | null>(null);

/** A download that genuinely failed, shown beside the buttons. */
const errorDescarga = ref<string | null>(null);

/** The server's chosen filename, or a safe fallback. */
function filenameFrom(response: Response, fallback: string): string {
    const header = response.headers.get('content-disposition') ?? '';

    const encoded = /filename\*=UTF-8''([^;]+)/i.exec(header);

    if (encoded) {
        try {
            return decodeURIComponent(encoded[1]);
        } catch {
            // Fall through to the plain filename.
        }
    }

    const plain = /filename="?([^";]+)"?/i.exec(header);

    return plain?.[1] ?? fallback;
}

/**
 * Download a report as a blob, so the screen can say "preparando…" and surface a
 * real failure.
 *
 * The previous anchor-fired-and-forget could neither show progress nor tell the
 * person that a 403 or a 500 had produced an HTML error page instead of a
 * document: the browser simply navigated away. Fetching first means a failure is
 * an error message on this screen, and a success is a file with the server's own
 * name.
 */
async function descargarArchivo(url: string, key: string, fallbackName: string): Promise<boolean> {
    errorDescarga.value = null;
    descargandoFormato.value = key;

    try {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: '*/*' },
        });

        if (!response.ok) {
            throw new Error(`La descarga respondió ${response.status}.`);
        }

        const blob = await response.blob();
        const objectUrl = URL.createObjectURL(blob);

        try {
            const enlace = document.createElement('a');

            enlace.href = objectUrl;
            enlace.download = filenameFrom(response, fallbackName);
            document.body.appendChild(enlace);
            enlace.click();
            enlace.remove();
        } finally {
            // The browser keeps the blob alive until the download starts.
            window.setTimeout(() => URL.revokeObjectURL(objectUrl), 10_000);
        }

        return true;
    } catch {
        errorDescarga.value = 'No se pudo descargar el archivo. Intente de nuevo.';

        return false;
    } finally {
        descargandoFormato.value = null;
    }
}

/**
 * The operational workbook, with the same progress and error handling as the
 * table exports.
 */
function descargarLibro(): void {
    errorLibro.value = null;
    descargandoLibro.value = true;

    void descargarArchivo(urlLibro(), 'libro', 'libro-operativo.xlsx')
        .then((ok) => {
            if (!ok) {
                errorLibro.value = errorDescarga.value;
            }
        })
        .finally(() => {
            descargandoLibro.value = false;
        });
}

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
    void descargarArchivo(urlDescarga(format), format, `reporte.${format}`);
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

    // The employer list is for the workbook's "una empresa" filter. A failure here must not stop
    // the report above from working — the workbook can export every company without it.
    try {
        empresasLibro.value = (await businessApi.clients.companyOptions()).companies;
    } catch {
        empresasLibro.value = [];
    }
});
</script>

<template>
    <div class="container-fluid py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0">Reportes</h1>

            <div v-if="puedeExportar" class="d-flex gap-2">
                <AppButton
                    variant="ghost"
                    :busy="descargandoFormato === 'xlsx'"
                    :disabled="descargandoFormato !== null"
                    @click="descargar('xlsx')"
                >
                    XLSX
                </AppButton>
                <AppButton
                    variant="ghost"
                    :busy="descargandoFormato === 'csv'"
                    :disabled="descargandoFormato !== null"
                    @click="descargar('csv')"
                >
                    CSV
                </AppButton>
                <AppButton
                    variant="primary"
                    :busy="descargandoFormato === 'pdf'"
                    :disabled="descargandoFormato !== null"
                    @click="descargar('pdf')"
                >
                    <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true" />
                    Descargar PDF de morosos
                </AppButton>
            </div>
        </div>

        <AppAlert v-if="errorDescarga" variant="danger" :message="errorDescarga" class="mb-3" />

        <!--
            FIX-EXPORT-IMPORT-03 Block C.

            A separate card, not a fourth button in the row above. C.1 asks for this to be a
            **clear and differentiated** option from "PDF de morosos", "CSV" and the individual
            planilla XLSX, and the difference is not cosmetic: those three describe a filtered
            population, this one rebuilds a monthly operational book for selected employers. A
            person who picks the wrong one gets a file that answers a question they did not ask,
            and only one of the two opens in a shape they recognise.
        -->
        <section
            v-if="puedeExportar"
            class="card mb-3"
            aria-labelledby="libro-operativo-title"
        >
            <div class="card-body">
                <h2 id="libro-operativo-title" class="h5 mb-1">
                    Exportar libro operativo (formato original)
                </h2>

                <p class="text-muted mb-3">
                    Reconstruye el libro mensual por empresas —una hoja por mes, un bloque por
                    empresa— con los datos actuales de Consultora DH. No es el archivo que se
                    subió: se arma desde el sistema y los colores se calculan con las
                    obligaciones y los pagos registrados.
                </p>

                <AppAlert
                    v-if="errorLibro"
                    variant="danger"
                    :message="errorLibro"
                    class="mb-3"
                />

                <div class="row g-2 align-items-end">
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="libro-anio">Año</label>
                        <select id="libro-anio" v-model.number="libro.anio" class="form-select">
                            <option v-for="a in aniosLibro" :key="a" :value="a">{{ a }}</option>
                        </select>
                    </div>

                    <div class="col-6 col-md-2">
                        <label class="form-label" for="libro-desde">Desde</label>
                        <select id="libro-desde" v-model.number="libro.desde" class="form-select">
                            <option v-for="m in MESES" :key="m.valor" :value="m.valor">
                                {{ m.etiqueta }}
                            </option>
                        </select>
                    </div>

                    <div class="col-6 col-md-2">
                        <label class="form-label" for="libro-hasta">Hasta</label>
                        <select id="libro-hasta" v-model.number="libro.hasta" class="form-select">
                            <option v-for="m in MESES" :key="m.valor" :value="m.valor">
                                {{ m.etiqueta }}
                            </option>
                        </select>
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="form-label" for="libro-empresa">Empresa</label>
                        <select id="libro-empresa" v-model="libro.empresa" class="form-select">
                            <option value="">Todas las empresas</option>
                            <option v-for="c in empresasLibro" :key="c.id" :value="String(c.id)">
                                {{ c.legal_name }}
                            </option>
                        </select>
                    </div>

                    <div class="col-12 col-md-2 d-grid">
                        <AppButton
                            :busy="descargandoLibro"
                            :disabled="descargandoLibro || libro.hasta < libro.desde"
                            @click="descargarLibro"
                        >
                            Descargar XLSX
                        </AppButton>
                    </div>
                </div>

                <p v-if="libro.hasta < libro.desde" class="text-danger mt-2 mb-0">
                    El mes «hasta» es anterior al mes «desde».
                </p>

                <!--
                    C.3's legend. Each entry says what the system had to know to produce the
                    colour, because the whole point is that these are computed and not copied from
                    the historical file.
                -->
                <details class="cdh-leyenda mt-3">
                    <summary>Leyenda de colores</summary>

                    <ul class="cdh-leyenda__lista">
                        <li v-for="entrada in LEYENDA_LIBRO" :key="entrada.texto" class="cdh-leyenda__item">
                            <span
                                class="cdh-leyenda__muestra"
                                :class="entrada.color"
                                aria-hidden="true"
                            />
                            <span>
                                <strong>{{ entrada.texto }}</strong>
                                <span class="text-muted"> — {{ entrada.detalle }}</span>
                            </span>
                        </li>
                    </ul>

                    <p class="text-muted mt-2 mb-0">
                        Un mes sin obligación registrada queda <strong>sin color</strong>: la
                        ausencia de un registro no significa que la persona pagó, ni que está en mora.
                    </p>
                </details>
            </div>
        </section>

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