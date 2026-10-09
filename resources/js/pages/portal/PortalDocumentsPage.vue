<script setup lang="ts">
import { onMounted, ref } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { fecha } from '@/composables/useFormatters';
import { a05 } from '@/services/a05';
import { ApiError } from '@/services/http';

import type { PortalDocument, PortalDocumentRequest } from '@/types/api';

/**
 * Documentos y solicitudes del portal.
 *
 * §47/§48: sólo lo que es de este cliente y sólo lo marcado visible para él. La
 * descarga va al endpoint con propiedad, que vuelve a comprobar la pertenencia en cada
 * petición: un identificador inventado en la barra de direcciones no abre nada.
 *
 * Un rechazo **no** desaparece: el motivo se muestra y el cliente puede subir un
 * reemplazo, que es una fila nueva y deja el intento anterior en la historia.
 */

const documentos = ref<PortalDocument[]>([]);
const solicitudes = ref<PortalDocumentRequest[]>([]);
const archivos = ref<Record<number, File | null>>({});

const cargando = ref(true);
const errorMessage = ref<string | null>(null);
const actionMessage = ref<string | null>(null);
const subiendo = ref<number | null>(null);

onMounted(async () => {
    try {
        const [listaDocumentos, listaSolicitudes] = await Promise.all([
            a05.portal.documents(),
            a05.portal.documentRequests(),
        ]);

        documentos.value = listaDocumentos.data;
        solicitudes.value = listaSolicitudes.data;
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudieron cargar sus documentos.';
    } finally {
        cargando.value = false;
    }
});

function seleccionarArchivo(id: number, event: Event): void {
    const input = event.target as HTMLInputElement;

    archivos.value[id] = input.files && input.files.length > 0 ? input.files[0] : null;
}

async function subirRespuesta(request: PortalDocumentRequest): Promise<void> {
    errorMessage.value = null;
    actionMessage.value = null;

    const archivo = archivos.value[request.id];

    if (!archivo) {
        errorMessage.value = 'Seleccione el archivo que desea enviar.';

        return;
    }

    subiendo.value = request.id;

    try {
        await a05.portal.uploadResponse(request.id, archivo);

        actionMessage.value = 'Documento enviado. Nuestro equipo lo revisará.';
        archivos.value[request.id] = null;
        const listaSolicitudes = await a05.portal.documentRequests();
        solicitudes.value = listaSolicitudes.data;
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo enviar el documento.';
    } finally {
        subiendo.value = null;
    }
}

function puedeResponder(status: string): boolean {
    return status === 'requested' || status === 'rejected';
}
</script>

<template>
    <div>
        <h1 class="h4 mb-3">Documentos y solicitudes</h1>

        <AppAlert v-if="errorMessage" variant="danger" :message="errorMessage" class="mb-3" />
        <AppAlert v-if="actionMessage" variant="success" :message="actionMessage" class="mb-3" />
        <AppLoading v-if="cargando" />

        <div v-else>
            <div class="card mb-3">
                <div class="card-header">
                    <h2 class="h6 mb-0">Solicitudes de documentos</h2>
                </div>

                <div v-if="solicitudes.length === 0" class="card-body">
                    <AppEmptyState
                        title="Sin solicitudes"
                        description="No tiene documentos pendientes por enviar."
                    />
                </div>

                <div v-else class="list-group list-group-flush">
                    <div
                        v-for="s in solicitudes"
                        :key="s.id"
                        class="list-group-item py-3"
                    >
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div>
                                <h3 class="h6 mb-1">{{ s.title }}</h3>
                                <p v-if="s.instructions" class="mb-1 small">{{ s.instructions }}</p>
                                <p class="small text-body-secondary mb-0">
                                    <span
                                        class="badge"
                                        :class="{
                                            'text-bg-primary': s.status === 'requested',
                                            'text-bg-success': s.status === 'approved',
                                            'text-bg-danger': s.status === 'rejected',
                                            'text-bg-info': s.status === 'received' || s.status === 'reviewed',
                                            'text-bg-secondary': s.status === 'cancelled',
                                        }"
                                    >
                                        {{ s.status_label }}
                                    </span>
                                    <span v-if="s.due_on" class="ms-2">vence {{ fecha(s.due_on) }}</span>
                                </p>
                                <p v-if="s.status === 'rejected' && s.decision_note" class="small text-danger mb-0 mt-1">
                                    Motivo del rechazo: {{ s.decision_note }}
                                </p>
                            </div>

                            <div v-if="puedeResponder(s.status)" class="text-end">
                                <input
                                    type="file"
                                    class="form-control form-control-sm mb-2"
                                    accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx,.csv"
                                    @change="seleccionarArchivo(s.id, $event)"
                                />
                                <AppButton
                                    variant="primary"
                                    size="sm"
                                    :loading="subiendo === s.id"
                                    @click="subirRespuesta(s)"
                                >
                                    {{ s.status === 'rejected' ? 'Enviar reemplazo' : 'Enviar documento' }}
                                </AppButton>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="h6 mb-0">Documentos disponibles</h2>
                </div>

                <div v-if="documentos.length === 0" class="card-body">
                    <AppEmptyState
                        title="Sin documentos"
                        description="Todavía no hay documentos disponibles para usted."
                    />
                </div>

                <div v-else class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <caption class="visually-hidden">Documentos disponibles</caption>
                        <thead>
                            <tr>
                                <th scope="col">Título</th>
                                <th scope="col">Tipo</th>
                                <th scope="col">Estado</th>
                                <th scope="col">Fecha</th>
                                <th scope="col" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in documentos" :key="d.id">
                                <td>{{ d.title }}</td>
                                <td>{{ d.document_type_name ?? '—' }}</td>
                                <td>{{ d.review_status_label }}</td>
                                <td>{{ fecha(d.created_at) }}</td>
                                <td class="text-end">
                                    <a
                                        :href="`/api/portal/documents/${d.id}/download`"
                                        class="btn btn-sm btn-outline-primary"
                                    >
                                        Descargar
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>