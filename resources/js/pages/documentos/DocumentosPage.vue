<script setup lang="ts">
import { onMounted, ref } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { fecha } from '@/composables/useFormatters';
import { useAuthStore } from '@/stores/auth';
import { a05 } from '@/services/a05';
import { ApiError } from '@/services/http';

import type { ClientDocument, ClientDocumentRequest, ClientDocumentType } from '@/types/api';

/**
 * Documentos y solicitudes.
 *
 * §36/§39: un documento rechazado **no se sobrescribe**. El reemplazo es una fila
 * nueva enlazada a la misma solicitud, de modo que la historia de lo que el cliente
 * envió y lo que se rechazó queda intacta. Por eso esta pantalla lista los
 * documentos de cada solicitud en lugar de mostrar sólo el último.
 */

const auth = useAuthStore();
const pestana = ref<'documentos' | 'solicitudes'>('solicitudes');

const types = ref<ClientDocumentType[]>([]);
const documents = ref<ClientDocument[]>([]);
const requests = ref<ClientDocumentRequest[]>([]);

const cargando = ref(true);
const errorMessage = ref<string | null>(null);
const actionMessage = ref<string | null>(null);

const nuevaSolicitud = ref({
    client_id: '',
    document_type_id: '',
    title: '',
    instructions: '',
    due_on: '',
});
const nuevoTipo = ref({ name: '', slug: '', description: '', retention_days: '' });
const archivos = ref<File[]>([]);

onMounted(async () => {
    await recargar();
});

async function recargar(): Promise<void> {
    cargando.value = true;
    errorMessage.value = null;

    try {
        const [listaTipos, listaSolicitudes, listaDocumentos] = await Promise.all([
            a05.documents.types().catch(() => ({ data: [] })),
            a05.documents.listRequests({ per_page: 25 }).catch(() => ({ data: [] })),
            a05.documents.list({ per_page: 25 }).catch(() => ({ data: [] })),
        ]);

        types.value = listaTipos.data ?? [];
        requests.value = listaSolicitudes.data ?? [];
        documents.value = listaDocumentos.data ?? [];
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudieron cargar los documentos.';
    } finally {
        cargando.value = false;
    }
}

async function crearSolicitud(): Promise<void> {
    errorMessage.value = null;
    actionMessage.value = null;

    try {
        await a05.documents.storeRequest({
            client_id: Number(nuevaSolicitud.value.client_id),
            document_type_id: Number(nuevaSolicitud.value.document_type_id),
            title: nuevaSolicitud.value.title,
            instructions: nuevaSolicitud.value.instructions || null,
            due_on: nuevaSolicitud.value.due_on || null,
        });

        nuevaSolicitud.value = {
            client_id: '',
            document_type_id: '',
            title: '',
            instructions: '',
            due_on: '',
        };
        actionMessage.value = 'Solicitud creada. El cliente ya puede verla en el portal.';
        await recargar();
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo crear la solicitud.';
    }
}

async function crearTipo(): Promise<void> {
    errorMessage.value = null;

    try {
        await a05.documents.storeType({
            name: nuevoTipo.value.name,
            slug: nuevoTipo.value.slug,
            description: nuevoTipo.value.description || null,
            retention_days: nuevoTipo.value.retention_days ? Number(nuevoTipo.value.retention_days) : null,
        });

        nuevoTipo.value = { name: '', slug: '', description: '', retention_days: '' };
        await recargar();
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo crear el tipo de documento.';
    }
}

async function marcarRecibida(request: ClientDocumentRequest): Promise<void> {
    errorMessage.value = null;

    try {
        await a05.documents.receive(request.id);
        await recargar();
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo marcar como recibida.';
    }
}

async function revisar(request: ClientDocumentRequest, decision: 'approve' | 'reject'): Promise<void> {
    errorMessage.value = null;

    const note = window.prompt(
        decision === 'approve'
            ? 'Nota de revisión (opcional):'
            : 'Motivo del rechazo (obligatorio):',
    );

    if (decision === 'reject' && (note === null || note.trim() === '')) {
        return;
    }

    try {
        await a05.documents.review(request.id, decision, note);
        await recargar();
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo revisar.';
    }
}

async function subirDocumento(): Promise<void> {
    errorMessage.value = null;
    actionMessage.value = null;

    const archivo = archivos.value[0];

    if (!archivo) {
        errorMessage.value = 'Seleccione un archivo.';

        return;
    }

    try {
        await a05.documents.upload({
            client_id: Number(prompt('Id del cliente:') ?? '0'),
            document_type_id: Number(prompt('Id del tipo de documento:') ?? '0'),
            title: archivo.name,
            visibility: 'internal',
            file: archivo,
        });

        archivos.value = [];
        actionMessage.value = 'Documento guardado.';
        await recargar();
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo guardar el documento.';
    }
}
</script>

<template>
    <div class="container-fluid py-4">
        <h1 class="h4 mb-3">Documentos</h1>

        <AppAlert v-if="errorMessage" variant="danger" :message="errorMessage" class="mb-3" />
        <AppAlert v-if="actionMessage" variant="success" :message="actionMessage" class="mb-3" />

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <button
                    class="nav-link"
                    :class="{ active: pestana === 'solicitudes' }"
                    type="button"
                    @click="pestana = 'solicitudes'"
                >
                    Solicitudes
                </button>
            </li>
            <li class="nav-item">
                <button
                    class="nav-link"
                    :class="{ active: pestana === 'documentos' }"
                    type="button"
                    @click="pestana = 'documentos'"
                >
                    Documentos
                </button>
            </li>
        </ul>

        <AppLoading v-if="cargando" />

        <div v-else class="row g-3">
            <div v-if="pestana === 'solicitudes'" class="col-12 col-lg-4">
                <div v-if="auth.can('documents.request')" class="card mb-3">
                    <div class="card-header">
                        <h2 class="h6 mb-0">Solicitar documento</h2>
                    </div>
                    <div class="card-body d-grid gap-2">
                        <div>
                            <label class="form-label" for="cliente-doc">Cliente (id)</label>
                            <input
                                id="cliente-doc"
                                v-model="nuevaSolicitud.client_id"
                                type="number"
                                class="form-control"
                            />
                        </div>
                        <div>
                            <label class="form-label" for="tipo-doc">Tipo de documento</label>
                            <select
                                id="tipo-doc"
                                v-model="nuevaSolicitud.document_type_id"
                                class="form-select"
                            >
                                <option value="">Seleccione…</option>
                                <option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="titulo-doc">Título</label>
                            <input id="titulo-doc" v-model="nuevaSolicitud.title" class="form-control" />
                        </div>
                        <div>
                            <label class="form-label" for="instrucciones">Instrucciones</label>
                            <textarea
                                id="instrucciones"
                                v-model="nuevaSolicitud.instructions"
                                class="form-control"
                                rows="2"
                            />
                        </div>
                        <div>
                            <label class="form-label" for="fecha-limite">Fecha límite</label>
                            <input
                                id="fecha-limite"
                                v-model="nuevaSolicitud.due_on"
                                type="date"
                                class="form-control"
                            />
                        </div>
                        <AppButton
                            variant="primary"
                            :disabled="nuevaSolicitud.client_id === '' || nuevaSolicitud.document_type_id === '' || nuevaSolicitud.title === ''"
                            @click="crearSolicitud"
                        >
                            Solicitar
                        </AppButton>
                    </div>
                </div>

                <div v-if="auth.can('documents.manage')" class="card">
                    <div class="card-header">
                        <h2 class="h6 mb-0">Nuevo tipo de documento</h2>
                    </div>
                    <div class="card-body d-grid gap-2">
                        <input v-model="nuevoTipo.name" class="form-control" placeholder="Nombre" />
                        <input v-model="nuevoTipo.slug" class="form-control" placeholder="slug" />
                        <input
                            v-model="nuevoTipo.retention_days"
                            class="form-control"
                            placeholder="Días de retención (opcional)"
                        />
                        <AppButton
                            variant="secondary"
                            :disabled="nuevoTipo.name === '' || nuevoTipo.slug === ''"
                            @click="crearTipo"
                        >
                            Crear tipo
                        </AppButton>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <div v-if="pestana === 'solicitudes'">
                    <div v-if="requests.length === 0" class="card">
                        <div class="card-body">
                            <AppEmptyState
                                title="Sin solicitudes"
                                description="No hay solicitudes de documentos."
                            />
                        </div>
                    </div>

                    <div v-else class="card">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <caption class="visually-hidden">Solicitudes de documentos</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Cliente</th>
                                        <th scope="col">Título</th>
                                        <th scope="col">Vence</th>
                                        <th scope="col">Estado</th>
                                        <th scope="col" />
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="r in requests" :key="r.id">
                                        <td>{{ r.client_name }}</td>
                                        <td>
                                            {{ r.title }}
                                            <span
                                                v-if="r.decision_note"
                                                class="d-block small text-body-secondary"
                                            >
                                                {{ r.decision_note }}
                                            </span>
                                        </td>
                                        <td>{{ fecha(r.due_on) }}</td>
                                        <td>
                                            <span
                                                class="badge"
                                                :class="{
                                                    'text-bg-primary': r.status === 'requested',
                                                    'text-bg-info': r.status === 'received' || r.status === 'reviewed',
                                                    'text-bg-success': r.status === 'approved',
                                                    'text-bg-danger': r.status === 'rejected',
                                                    'text-bg-secondary': r.status === 'cancelled',
                                                }"
                                            >
                                                {{ r.status_label }}
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <AppButton
                                                    v-if="auth.can('documents.review') && r.status === 'requested'"
                                                    variant="ghost"
                                                    @click="marcarRecibida(r)"
                                                >
                                                    Recibida
                                                </AppButton>
                                                <template v-if="auth.can('documents.review') && r.status === 'received'">
                                                    <AppButton variant="ghost" @click="revisar(r, 'approve')">
                                                        Aprobar
                                                    </AppButton>
                                                    <AppButton variant="ghost" @click="revisar(r, 'reject')">
                                                        Rechazar
                                                    </AppButton>
                                                </template>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div v-else>
                    <div v-if="auth.can('documents.manage')" class="card mb-3">
                        <div class="card-header">
                            <h2 class="h6 mb-0">Adjuntar documento</h2>
                        </div>
                        <div class="card-body">
                            <input
                                type="file"
                                class="form-control"
                                accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx,.csv"
                                @change="archivos = ($event.target as HTMLInputElement).files ? Array.from(($event.target as HTMLInputElement).files!) : []"
                            />
                            <AppButton variant="primary" class="mt-2" @click="subirDocumento">
                                Guardar
                            </AppButton>
                        </div>
                    </div>

                    <div v-if="documents.length === 0" class="card">
                        <div class="card-body">
                            <AppEmptyState
                                title="Sin documentos"
                                description="No hay documentos registrados."
                            />
                        </div>
                    </div>

                    <div v-else class="card">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <caption class="visually-hidden">Documentos</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Cliente</th>
                                        <th scope="col">Título</th>
                                        <th scope="col">Revisión</th>
                                        <th scope="col">Retención hasta</th>
                                        <th scope="col" />
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="d in documents" :key="d.id">
                                        <td>{{ d.client_name }}</td>
                                        <td>{{ d.title }}</td>
                                        <td>{{ d.review_status_label }}</td>
                                        <td>{{ fecha(d.retention_until) }}</td>
                                        <td class="text-end">
                                            <a
                                                :href="`/api/documents/${d.id}/download`"
                                                class="btn btn-sm btn-outline-secondary"
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
        </div>
    </div>
</template>