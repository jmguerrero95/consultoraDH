<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppFormField from '@/components/ui/AppFormField.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import AppModal from '@/components/ui/AppModal.vue';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';
import { useToastStore } from '@/stores/toast';

import type { TelegramEndpoint, TelegramEventPreference } from '@/types/api';

/**
 * The Telegram destinations an administrator can notify.
 *
 * ## Why the event list is spelled out here
 *
 * Which events a destination subscribes to is a small, closed set that the server
 * also validates (`Rule::in` on `event_preferences.*`). Rendering it from one
 * place means the page cannot offer a subscription the server would refuse, and a
 * subscription it does accept cannot be spelled differently on the two sides.
 *
 * ## Why a destination can be scoped to one conversation
 *
 * A destination is either global or bound to a single conversation. Binding is
 * what keeps a client's thread from being announced to a channel meant for
 * another one, so the scope is stated plainly on the row rather than left for
 * someone to infer from the chat id.
 */

const toast = useToastStore();

const EVENT_PREFERENCES: { value: TelegramEventPreference; label: string }[] = [
    { value: 'support_sla_breach', label: 'Incumplimiento de SLA' },
    { value: 'urgent_unassigned', label: 'Urgente sin asignar' },
    { value: 'automation_alert', label: 'Alerta de automatización' },
    { value: 'document_overdue', label: 'Documento vencido' },
];

const endpoints = ref<TelegramEndpoint[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

let requestId = 0;

async function load(): Promise<void> {
    const current = ++requestId;

    loading.value = true;
    error.value = null;

    try {
        const payload = await businessApi.telegram.list();

        if (current !== requestId) {
            return;
        }

        endpoints.value = payload;
    } catch (cause) {
        if (current !== requestId) {
            return;
        }

        endpoints.value = [];
        error.value = cause instanceof ApiError ? cause.message : 'No fue posible cargar los destinos.';
    } finally {
        if (current === requestId) {
            loading.value = false;
        }
    }
}

onMounted(load);

function eventLabel(value: string): string {
    return EVENT_PREFERENCES.find((event) => event.value === value)?.label ?? value;
}

function scopeLabel(endpoint: TelegramEndpoint): string {
    return endpoint.conversation_id === null || endpoint.conversation_id === undefined
        ? 'Todos los chats'
        : `Conversación #${endpoint.conversation_id}`;
}

// --- Create and edit ---------------------------------------------------------

const formOpen = ref(false);
const editing = ref<TelegramEndpoint | null>(null);
const submitting = ref(false);
const formError = ref<string | null>(null);

const form = reactive({
    label: '',
    chat_id: '',
    conversation_id: '',
    enabled: true,
    event_preferences: [] as TelegramEventPreference[],
});

function openCreate(): void {
    editing.value = null;
    form.label = '';
    form.chat_id = '';
    form.conversation_id = '';
    form.enabled = true;
    form.event_preferences = [];
    formError.value = null;

    formOpen.value = true;
}

function openEdit(endpoint: TelegramEndpoint): void {
    editing.value = endpoint;
    form.label = endpoint.label;
    form.chat_id = endpoint.chat_id;
    form.conversation_id = endpoint.conversation_id === null ? '' : String(endpoint.conversation_id);
    form.enabled = endpoint.enabled;
    form.event_preferences = [...(endpoint.event_preferences ?? [])];
    formError.value = null;

    formOpen.value = true;
}

async function save(): Promise<void> {
    submitting.value = true;
    formError.value = null;

    const payload = {
        label: form.label,
        chat_id: form.chat_id,
        conversation_id: form.conversation_id === '' ? null : Number(form.conversation_id),
        enabled: form.enabled,
        event_preferences: form.event_preferences,
    };

    try {
        if (editing.value === null) {
            await businessApi.telegram.create(payload);
            toast.success('Destino creado');
        } else {
            await businessApi.telegram.update(editing.value.id, payload);
            toast.success('Destino actualizado');
        }

        formOpen.value = false;
        await load();
    } catch (cause) {
        formError.value = cause instanceof ApiError ? cause.message : 'No fue posible guardar el destino.';
    } finally {
        submitting.value = false;
    }
}

// --- Test and toggle ---------------------------------------------------------

const busyEndpointId = ref<number | null>(null);

async function sendTest(endpoint: TelegramEndpoint): Promise<void> {
    busyEndpointId.value = endpoint.id;

    try {
        const result = await businessApi.telegram.test(endpoint.id);
        toast.success(result.message);
    } catch (cause) {
        toast.error(cause instanceof ApiError ? cause.message : 'No fue posible encolar el mensaje.');
    } finally {
        busyEndpointId.value = null;
    }
}

async function toggleEnabled(endpoint: TelegramEndpoint): Promise<void> {
    busyEndpointId.value = endpoint.id;

    try {
        await businessApi.telegram.update(endpoint.id, { enabled: !endpoint.enabled });
        await load();
    } catch (cause) {
        toast.error(cause instanceof ApiError ? cause.message : 'No fue posible cambiar el estado.');
    } finally {
        busyEndpointId.value = null;
    }
}
</script>

<template>
    <section class="cdh-content">
        <header class="cdh-page-header">
            <div>
                <h1 class="cdh-page-header__title">Notificaciones por Telegram</h1>
                <p class="cdh-page-header__subtitle">
                    {{ endpoints.length }}
                    {{ endpoints.length === 1 ? 'destino configurado' : 'destinos configurados' }}
                </p>
            </div>

            <AppButton icon="bi-plus-lg" @click="openCreate">Nuevo destino</AppButton>
        </header>

        <AppAlert v-if="error" variant="danger" :title="error" class="mb-4" />

        <AppLoading v-if="loading && endpoints.length === 0" label="Cargando destinos" />

        <AppEmptyState
            v-else-if="!error && endpoints.length === 0"
            title="No hay destinos de Telegram"
            description="Cree un destino con el chat id que entrega el bot para empezar a recibir avisos."
            action-label="Nuevo destino"
            @action="openCreate"
        />

        <div v-else class="cdh-table-wrap">
            <table class="cdh-table">
                <caption class="cdh-visually-hidden">Destinos de notificación por Telegram</caption>
                <thead>
                    <tr>
                        <th scope="col">Etiqueta</th>
                        <th scope="col">Chat ID</th>
                        <th scope="col">Alcance</th>
                        <th scope="col">Eventos</th>
                        <th scope="col">Estado</th>
                        <th scope="col"><span class="cdh-visually-hidden">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="endpoint in endpoints" :key="endpoint.id">
                        <td><strong>{{ endpoint.label }}</strong></td>
                        <td><code>{{ endpoint.chat_id }}</code></td>
                        <td>{{ scopeLabel(endpoint) }}</td>
                        <td>
                            <span
                                v-for="preference in endpoint.event_preferences ?? []"
                                :key="preference"
                                class="cdh-badge cdh-badge--info"
                            >
                                {{ eventLabel(preference) }}
                            </span>
                            <span
                                v-if="(endpoint.event_preferences ?? []).length === 0"
                                class="cdh-text-muted"
                            >
                                Sin eventos
                            </span>
                        </td>
                        <td>
                            <span
                                class="cdh-badge"
                                :class="endpoint.enabled ? 'cdh-badge--success' : 'cdh-badge--neutral'"
                            >
                                {{ endpoint.enabled ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td class="cdh-table__actions">
                            <AppButton
                                variant="link"
                                :disabled="busyEndpointId === endpoint.id"
                                @click="toggleEnabled(endpoint)"
                            >
                                {{ endpoint.enabled ? 'Desactivar' : 'Activar' }}
                            </AppButton>
                            <AppButton
                                variant="link"
                                :disabled="busyEndpointId === endpoint.id"
                                @click="sendTest(endpoint)"
                            >
                                Enviar prueba
                            </AppButton>
                            <AppButton variant="link" @click="openEdit(endpoint)">Editar</AppButton>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <AppModal
            :open="formOpen"
            :title="editing === null ? 'Nuevo destino de Telegram' : 'Editar destino de Telegram'"
            confirm-label="Guardar"
            :busy="submitting"
            @confirm="save"
            @cancel="formOpen = false"
        >
            <AppAlert v-if="formError" variant="danger" :title="formError" class="mb-3" />

            <form class="cdh-form" @submit.prevent>
                <AppFormField
                    label="Etiqueta"
                    name="telegram-label"
                    v-model="form.label"
                    required
                    hint="Un nombre legible para distinguir el destino."
                />

                <AppFormField
                    label="Chat ID"
                    name="telegram-chat-id"
                    v-model="form.chat_id"
                    required
                    hint="El chat al que el bot puede escribir."
                />

                <div class="cdh-stack-4">
                    <label class="cdh-form-label" for="telegram-conversation">
                        Conversación (opcional)
                    </label>
                    <input
                        id="telegram-conversation"
                        v-model="form.conversation_id"
                        class="form-control"
                        type="number"
                        inputmode="numeric"
                        placeholder="Vacío para todos los chats"
                    />
                    <p class="cdh-form-hint">
                        Al asociarlo a una conversación, este destino solo avisa sobre ese hilo.
                    </p>
                </div>

                <fieldset class="cdh-card cdh-card__body">
                    <legend class="cdh-form-label">Eventos</legend>

                    <div v-for="event in EVENT_PREFERENCES" :key="event.value" class="form-check">
                        <input
                            :id="`telegram-event-${event.value}`"
                            v-model="form.event_preferences"
                            class="form-check-input"
                            type="checkbox"
                            :value="event.value"
                        />
                        <label class="form-check-label" :for="`telegram-event-${event.value}`">
                            {{ event.label }}
                        </label>
                    </div>
                </fieldset>
            </form>
        </AppModal>
    </section>
</template>