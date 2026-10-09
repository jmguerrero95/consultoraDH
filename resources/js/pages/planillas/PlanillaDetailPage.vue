<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { pesos } from '@/composables/useFormatters';
import { useAuthStore } from '@/stores/auth';
import { a05 } from '@/services/a05';
import { ApiError } from '@/services/http';

import type { PlanillaDetail, PlanillaValidation, ValidationFinding } from '@/types/api';

/**
 * La ficha de una planilla.
 *
 * El panel de validación separa **errores** de **avisos** porque significan cosas
 * distintas (§22): un error impide pasar a «lista», un aviso no. Presentarlos en una
 * sola lista obligaría a quien lee a adivinar cuál es cuál.
 *
 * §17: los pasos son acciones con nombre, no un PATCH que escriba un estado. Una
 * planilla pagada es histórico y esta pantalla no ofrece devolverla a borrador.
 */

const auth = useAuthStore();

const sheet = ref<PlanillaDetail | null>(null);
const loading = ref(true);
const errorMessage = ref<string | null>(null);
const actionMessage = ref<string | null>(null);
const actionError = ref<string | null>(null);
const working = ref(false);

const validacion = ref<PlanillaValidation | null>(null);
const mostrarValidacion = ref(false);

const formularioPago = ref({ paid_on: '', sheet_number: '', reference: '', submitted_on: '' });
const motivoCancelacion = ref('');

const archivos = ref<File[]>([]);
const tipoArchivo = ref('operator_pdf');

const errores = computed<ValidationFinding[]>(() => validacion.value?.errors ?? []);
const avisos = computed<ValidationFinding[]>(() => validacion.value?.warnings ?? []);

const route = useRoute();
const id = computed(() => Number(route.params.id));

async function cargar(): Promise<void> {
    loading.value = true;
    errorMessage.value = null;

    try {
        sheet.value = await a05.planillas.show(id.value);
        formularioPago.value.sheet_number = sheet.value.sheet_number ?? '';
        formularioPago.value.reference = sheet.value.reference ?? '';
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo cargar la planilla.';
    } finally {
        loading.value = false;
    }
}

onMounted(cargar);

async function validar(): Promise<void> {
    actionError.value = null;
    actionMessage.value = null;
    working.value = true;

    try {
        validacion.value = await a05.planillas.validate(id.value);
        mostrarValidacion.value = true;

        if (validacion.value.valid) {
            actionMessage.value = 'La planilla quedó lista para enviar.';
            await cargar();
        }
    } catch (e) {
        actionError.value = e instanceof ApiError ? e.message : 'No se pudo validar la planilla.';
    } finally {
        working.value = false;
    }
}

async function enviar(): Promise<void> {
    actionError.value = null;
    actionMessage.value = null;
    working.value = true;

    try {
        await a05.planillas.submit(id.value, {
            sheet_number: formularioPago.value.sheet_number || null,
            reference: formularioPago.value.reference || null,
            submitted_on: formularioPago.value.submitted_on,
        });

        actionMessage.value = 'Planilla enviada.';
        await cargar();
    } catch (e) {
        actionError.value = e instanceof ApiError ? e.message : 'No se pudo enviar la planilla.';
    } finally {
        working.value = false;
    }
}

async function marcarPagada(): Promise<void> {
    actionError.value = null;
    actionMessage.value = null;
    working.value = true;

    try {
        await a05.planillas.markPaid(id.value, { paid_on: formularioPago.value.paid_on });
        actionMessage.value = 'Planilla marcada como pagada.';
        await cargar();
    } catch (e) {
        actionError.value = e instanceof ApiError ? e.message : 'No se pudo marcar como pagada.';
    } finally {
        working.value = false;
    }
}

async function devolverABorrador(): Promise<void> {
    actionError.value = null;
    working.value = true;

    try {
        await a05.planillas.returnToDraft(id.value);
        await cargar();
    } catch (e) {
        actionError.value = e instanceof ApiError ? e.message : 'No se pudo devolver a borrador.';
    } finally {
        working.value = false;
    }
}

async function cancelar(): Promise<void> {
    actionError.value = null;
    working.value = true;

    try {
        await a05.planillas.cancel(id.value, motivoCancelacion.value);
        motivoCancelacion.value = '';
        await cargar();
    } catch (e) {
        actionError.value = e instanceof ApiError ? e.message : 'No se pudo cancelar.';
    } finally {
        working.value = false;
    }
}

async function subirArchivo(): Promise<void> {
    actionError.value = null;
    actionMessage.value = null;

    const archivo = archivos.value[0];

    if (!archivo) {
        actionError.value = 'Seleccione un archivo.';

        return;
    }

    working.value = true;

    try {
        await a05.planillas.uploadFile(id.value, archivo, tipoArchivo.value);
        archivos.value = [];
        actionMessage.value = 'Archivo adjuntado.';
        await cargar();
    } catch (e) {
        actionError.value = e instanceof ApiError ? e.message : 'No se pudo adjuntar el archivo.';
    } finally {
        working.value = false;
    }
}

function descargar(ruta: string): void {
    window.open(ruta, '_blank', 'noopener');
}
</script>

<template>
    <div class="container-fluid py-4">
        <AppLoading v-if="loading" />

        <AppAlert
            v-else-if="errorMessage"
            variant="danger"
            :message="errorMessage"
            class="mb-3"
        />

        <template v-else-if="sheet">
            <nav aria-label="Miga de pan" class="mb-2">
                <RouterLink to="/planillas">Planillas</RouterLink>
                <span class="text-body-secondary"> / </span>
                <span>{{ sheet.company_name }}</span>
            </nav>

            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h1 class="h4 mb-1">
                        Planilla {{ sheet.operator_label }} — {{ sheet.company_name }}
                    </h1>
                    <p class="text-body-secondary mb-0">
                        Periodo {{ sheet.period_month?.slice(0, 7) }} ·
                        {{ sheet.period_month?.slice(5, 7) }}/{{ sheet.period_month?.slice(0, 4) }}
                        <span class="ms-2 badge" :class="{
                            'text-bg-secondary': sheet.status === 'draft',
                            'text-bg-info': sheet.status === 'ready',
                            'text-bg-primary': sheet.status === 'submitted',
                            'text-bg-success': sheet.status === 'paid',
                            'text-bg-danger': sheet.status === 'cancelled',
                        }">{{ sheet.status_label }}</span>
                    </p>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <AppButton
                        variant="ghost"
                        @click="descargar(`/api/planillas/${sheet.id}/export/xlsx`)"
                    >
                        <i class="bi bi-file-earmark-excel me-1" aria-hidden="true" />
                        XLSX
                    </AppButton>
                    <AppButton
                        variant="ghost"
                        @click="descargar(`/api/planillas/${sheet.id}/export/pdf`)"
                    >
                        <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true" />
                        PDF
                    </AppButton>
                </div>
            </div>

            <AppAlert v-if="actionMessage" variant="success" :message="actionMessage" class="mb-3" />
            <AppAlert v-if="actionError" variant="danger" :message="actionError" class="mb-3" />

            <div class="row g-3">
                <div class="col-12 col-lg-8">
                    <div class="card mb-3">
                        <div class="card-header">
                            <h2 class="h6 mb-0">Personas incluidas</h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-2">
                                Total liquidado:
                                <strong>{{ pesos(sheet.total_liquidated_cop) }}</strong>
                                <span class="text-body-secondary">
                                    ({{ sheet.included_line_count }} de {{ sheet.line_count }} líneas)
                                </span>
                            </p>
                        </div>
                        <div v-if="sheet.lines.length > 0" class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <caption class="visually-hidden">Personas de la planilla</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Persona</th>
                                        <th scope="col">Cargo</th>
                                        <th scope="col">EPS</th>
                                        <th scope="col">AFP</th>
                                        <th scope="col">ARL</th>
                                        <th scope="col">CCF</th>
                                        <th scope="col" class="text-end">Valor liquidado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="linea in sheet.lines"
                                        :key="linea.id"
                                        :class="{ 'table-warning': !linea.included }"
                                    >
                                        <td>
                                            {{ linea.client_name }}
                                            <span class="d-block small text-body-secondary">
                                                {{ linea.document_type }} {{ linea.document_number }}
                                            </span>
                                            <span
                                                v-if="!linea.included"
                                                class="d-block small text-danger"
                                            >
                                                Excluida: {{ linea.exclusion_reason }}
                                            </span>
                                        </td>
                                        <td>{{ linea.job_title ?? '—' }}</td>
                                        <td>{{ linea.eps_name ?? '—' }}</td>
                                        <td>{{ linea.afp_name ?? '—' }}</td>
                                        <td>{{ linea.arl_name ?? '—' }}</td>
                                        <td>{{ linea.ccf_name ?? '—' }}</td>
                                        <td class="text-end">
                                            {{ pesos(linea.liquidated_amount_cop) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <AppEmptyState
                            v-else
                            title="Sin líneas"
                            description="Esta planilla no tiene personas registradas."
                        />
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h2 class="h6 mb-0">Comprobantes</h2>
                        </div>
                        <div class="card-body">
                            <ul v-if="sheet.files.length > 0" class="list-group list-group-flush">
                                <li
                                    v-for="archivo in sheet.files"
                                    :key="archivo.id"
                                    class="list-group-item d-flex justify-content-between align-items-center px-0"
                                >
                                    <span>
                                        <i class="bi bi-paperclip me-1" aria-hidden="true" />
                                        {{ archivo.original_name }}
                                        <span class="small text-body-secondary">({{ archivo.kind }})</span>
                                    </span>
                                    <AppButton
                                        variant="ghost"
                                        @click="descargar(`/api/planillas/${sheet.id}/files/${archivo.id}`)"
                                    >
                                        Descargar
                                    </AppButton>
                                </li>
                            </ul>
                            <p v-else class="text-body-secondary mb-3">
                                Sin comprobantes adjuntos.
                            </p>

                            <div v-if="auth.can('planillas.update')" class="row g-2 align-items-end">
                                <div class="col-12 col-sm-4">
                                    <label class="form-label" for="tipo">Tipo</label>
                                    <select id="tipo" v-model="tipoArchivo" class="form-select">
                                        <option value="operator_pdf">PDF del operador</option>
                                        <option value="payment_receipt">Comprobante de pago</option>
                                        <option value="other">Otro</option>
                                    </select>
                                </div>
                                <div class="col-12 col-sm-5">
                                    <label class="form-label" for="archivo">Archivo</label>
                                    <input
                                        id="archivo"
                                        type="file"
                                        class="form-control"
                                        accept=".pdf,.jpg,.jpeg,.png"
                                        @change="archivos = ($event.target as HTMLInputElement).files ? Array.from(($event.target as HTMLInputElement).files!) : []"
                                    />
                                </div>
                                <div class="col-12 col-sm-3">
                                    <AppButton
                                        variant="primary"
                                        :loading="working"
                                        @click="subirArchivo"
                                    >
                                        Adjuntar
                                    </AppButton>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-4">
                    <div class="card mb-3">
                        <div class="card-header">
                            <h2 class="h6 mb-0">Acciones</h2>
                        </div>
                        <div class="card-body d-grid gap-2">
                            <AppButton
                                v-if="auth.can('planillas.validate') && sheet.status === 'draft'"
                                variant="primary"
                                :loading="working"
                                @click="validar"
                            >
                                Validar
                            </AppButton>

                            <AppButton
                                v-if="auth.can('planillas.update') && sheet.status === 'ready'"
                                variant="ghost"
                                :loading="working"
                                @click="devolverABorrador"
                            >
                                Devolver a borrador
                            </AppButton>

                            <template v-if="auth.can('planillas.submit') && sheet.status === 'ready'">
                                <div>
                                    <label class="form-label" for="numero">Número de planilla</label>
                                    <input
                                        id="numero"
                                        v-model="formularioPago.sheet_number"
                                        class="form-control"
                                    />
                                </div>
                                <div>
                                    <label class="form-label" for="referencia">Referencia</label>
                                    <input
                                        id="referencia"
                                        v-model="formularioPago.reference"
                                        class="form-control"
                                    />
                                </div>
                                <div>
                                    <label class="form-label" for="enviada">Fecha de envío</label>
                                    <input
                                        id="enviada"
                                        v-model="formularioPago.submitted_on"
                                        type="date"
                                        class="form-control"
                                    />
                                </div>
                                <AppButton
                                    variant="primary"
                                    :loading="working"
                                    @click="enviar"
                                >
                                    Marcar enviada
                                </AppButton>
                            </template>

                            <template v-if="auth.can('planillas.mark_paid') && sheet.status === 'submitted'">
                                <div>
                                    <label class="form-label" for="pagada">Fecha de pago</label>
                                    <input
                                        id="pagada"
                                        v-model="formularioPago.paid_on"
                                        type="date"
                                        class="form-control"
                                    />
                                </div>
                                <AppButton
                                    variant="primary"
                                    :loading="working"
                                    @click="marcarPagada"
                                >
                                    Marcar pagada
                                </AppButton>
                                <p class="small text-body-secondary mb-0">
                                    Requiere al menos un comprobante de pago adjunto.
                                </p>
                            </template>

                            <template v-if="auth.can('planillas.cancel') && sheet.status !== 'cancelled' && sheet.status !== 'paid'">
                                <hr class="my-2" />
                                <div>
                                    <label class="form-label" for="motivo">Motivo de cancelación</label>
                                    <textarea
                                        id="motivo"
                                        v-model="motivoCancelacion"
                                        class="form-control"
                                        rows="2"
                                    />
                                </div>
                                <AppButton
                                    variant="danger"
                                    :loading="working"
                                    @click="cancelar"
                                >
                                    Cancelar planilla
                                </AppButton>
                            </template>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h2 class="h6 mb-0">Identificación</h2>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0 small">
                                <dt class="col-5">Referencia</dt>
                                <dd class="col-7">{{ sheet.reference ?? '—' }}</dd>
                                <dt class="col-5">Número</dt>
                                <dd class="col-7">{{ sheet.sheet_number ?? '—' }}</dd>
                                <dt class="col-5">Enviada</dt>
                                <dd class="col-7">{{ sheet.submitted_on ?? '—' }}</dd>
                                <dt class="col-5">Pagada</dt>
                                <dd class="col-7">{{ sheet.paid_on ?? '—' }}</dd>
                                <dt class="col-5">Revisión</dt>
                                <dd class="col-7">{{ sheet.revision }}</dd>
                            </dl>
                            <p class="small text-body-secondary mt-2 mb-0">
                                Documento interno. No es un documento oficial de operador PILA.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!--
                §22: errors and warnings are two lists, never one. An error blocks
                `ready`; a warning does not. Merging them would make a reviewer read a
                sheet as refused for something the system cannot justify as a refusal.
            -->
            <div v-if="mostrarValidacion && validacion" class="card mt-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0">Resultado de la validación</h2>
                    <button
                        type="button"
                        class="btn-close"
                        aria-label="Cerrar resultado de la validación"
                        @click="mostrarValidacion = false"
                    />
                </div>
                <div class="card-body">
                    <div v-if="errores.length > 0" class="mb-3">
                        <h3 class="h6 text-danger">
                            Errores que impiden continuar ({{ errores.length }})
                        </h3>
                        <ul class="list-group">
                            <li
                                v-for="(hallazgo, indice) in errores"
                                :key="`e-${indice}`"
                                class="list-group-item list-group-item-danger"
                                data-testid="validacion-error"
                            >
                                {{ hallazgo.message }}
                            </li>
                        </ul>
                    </div>

                    <div v-if="avisos.length > 0">
                        <h3 class="h6 text-warning-emphasis">
                            Avisos ({{ avisos.length }})
                        </h3>
                        <ul class="list-group">
                            <li
                                v-for="(hallazgo, indice) in avisos"
                                :key="`w-${indice}`"
                                class="list-group-item list-group-item-warning"
                                data-testid="validacion-warning"
                            >
                                {{ hallazgo.message }}
                            </li>
                        </ul>
                    </div>

                    <p
                        v-if="errores.length === 0 && avisos.length === 0"
                        class="text-body-secondary mb-0"
                    >
                        Sin errores ni avisos.
                    </p>
                </div>
            </div>
        </template>
    </div>
</template>