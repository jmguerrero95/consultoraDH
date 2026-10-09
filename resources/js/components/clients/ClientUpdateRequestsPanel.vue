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

import type { ClientProfileUpdateRequest } from '@/types/api';

/**
 * The proposals a client has made about their own data, and the decision to take on them.
 *
 * A05-R1 §3: the permissions existed and nothing consumed them, so a client could submit
 * a change and staff had no way to act on it. This panel lives inside the client record
 * rather than in a module of its own — a proposal is about one client, and putting it on
 * a separate screen would make the reviewer open two pages to answer one question.
 *
 * Approving runs A02's `UpdateClient`, so the change lands through the same action, with
 * the same audit event, as any other edit of a client.
 */

const props = defineProps<{ clientId: number }>();

const auth = useAuthStore();

const requests = ref<ClientProfileUpdateRequest[]>([]);
const loading = ref(true);
const errorMessage = ref<string | null>(null);
const actionMessage = ref<string | null>(null);
const working = ref<number | null>(null);

async function cargar(): Promise<void> {
    loading.value = true;
    errorMessage.value = null;

    try {
        const respuesta = await a05.clients.updateRequests(props.clientId);

        // A shape that is not a list is a failed load, not an empty one. This panel sits
        // inside the ficha, so letting it throw on a surprise payload would take down the
        // whole page over a section that is not what the reader came for; the error state
        // below says plainly that these requests could not be read.
        if (!Array.isArray(respuesta.data)) {
            throw new TypeError('la respuesta no es una lista de solicitudes');
        }

        requests.value = respuesta.data;
    } catch (e) {
        // §60: a failed request is not an empty list.
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudieron cargar las solicitudes.';
    } finally {
        loading.value = false;
    }
}

onMounted(cargar);

async function aprobar(request: ClientProfileUpdateRequest): Promise<void> {
    errorMessage.value = null;
    actionMessage.value = null;
    working.value = request.id;

    try {
        await a05.clients.approveUpdateRequest(request.id);
        actionMessage.value = 'Solicitud aprobada y aplicada.';
        await cargar();
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo aprobar la solicitud.';
    } finally {
        working.value = null;
    }
}

async function rechazar(request: ClientProfileUpdateRequest): Promise<void> {
    const motivo = window.prompt('Motivo del rechazo (obligatorio):');

    if (motivo === null || motivo.trim() === '') {
        return;
    }

    errorMessage.value = null;
    actionMessage.value = null;
    working.value = request.id;

    try {
        await a05.clients.rejectUpdateRequest(request.id, motivo.trim());
        actionMessage.value = 'Solicitud rechazada.';
        await cargar();
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo rechazar la solicitud.';
    } finally {
        working.value = null;
    }
}
</script>

<template>
    <article class="cdh-card" data-testid="client-update-requests">
        <div class="cdh-card__header">
            <h2 class="cdh-card__title">
                Solicitudes de actualización del perfil
                <span
                    v-if="requests.filter((r) => r.status === 'pending').length > 0"
                    class="badge text-bg-warning ms-2"
                >
                    {{ requests.filter((r) => r.status === 'pending').length }} pendiente(s)
                </span>
            </h2>
        </div>

        <AppLoading v-if="loading" />

        <template v-else-if="auth.can('client_update_requests.view')">
            <AppAlert v-if="errorMessage" variant="danger" :message="errorMessage" class="m-3" />
            <AppAlert
                v-if="actionMessage"
                variant="success"
                :message="actionMessage"
                class="m-3"
            />

            <AppEmptyState
                v-if="requests.length === 0"
                title="Sin solicitudes"
                description="Este cliente no ha propuesto cambios de perfil."
            />

            <ul v-else class="cdh-card__body list-group list-group-flush">
                <li v-for="r in requests" :key="r.id" class="list-group-item">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <div>
                            <span
                                class="badge"
                                :class="{
                                    'text-bg-primary': r.status === 'pending',
                                    'text-bg-success': r.status === 'approved',
                                    'text-bg-danger': r.status === 'rejected',
                                    'text-bg-secondary': r.status === 'cancelled',
                                }"
                            >
                                {{ r.status_label }}
                            </span>
                            <span class="small text-body-secondary ms-2">
                                {{ fecha(r.created_at) }}
                            </span>

                            <ul class="small mb-1 mt-2">
                                <li v-for="(valor, campo) in r.proposed_changes" :key="campo">
                                    <strong>{{ campo }}</strong>: {{ valor }}
                                </li>
                            </ul>

                            <p v-if="r.review_note" class="small text-body-secondary mb-0">
                                Nota: {{ r.review_note }}
                            </p>
                        </div>

                        <div
                            v-if="auth.can('client_update_requests.review') && r.status === 'pending'"
                            class="d-flex gap-2 align-items-start"
                        >
                            <AppButton
                                variant="primary"
                                size="sm"
                                :loading="working === r.id"
                                @click="aprobar(r)"
                            >
                                Aprobar
                            </AppButton>
                            <AppButton
                                variant="ghost"
                                size="sm"
                                :loading="working === r.id"
                                @click="rechazar(r)"
                            >
                                Rechazar
                            </AppButton>
                        </div>
                    </div>
                </li>
            </ul>
        </template>
    </article>
</template>