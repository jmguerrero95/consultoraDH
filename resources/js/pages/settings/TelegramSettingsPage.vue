<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppFormField from '@/components/ui/AppFormField.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import AppModal from '@/components/ui/AppModal.vue';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';
import { useToastStore } from '@/stores/toast';

import type { TelegramEndpoint, TelegramEventDefinition, TelegramEventPreference } from '@/types/api';

/**
 * The Telegram destinations an administrator can notify.
 *
 * ## The event list comes from the server catalogue
 *
 * Creating a subscription the server would refuse is a control that lies. The
 * page therefore renders `admin_available` from `GET /api/telegram/events`:
 * the events an administrative destination may actually choose, with the
 * server's own labels. Personal-only events (`task_reminder`,
 * `report_available`) are delivered to one employee's private chat and are not
 * administrative subscriptions; `urgent_unassigned` has no producer at all.
 *
 * The hard-coded list below is only the fallback used when that request fails,
 * and it contains exactly the administrable keys so the screen still works.
 *
 * ## Why a destination can be scoped to one conversation
 *
 * A destination is either global or bound to a single conversation. Binding is
 * what keeps a client's thread from being announced to a channel meant for
 * another one, so the scope is stated plainly on the row rather than left for
 * someone to infer from the chat id.
 */

const toast = useToastStore();

/** Delivered to the private chat of one person, never to a shared destination. */
const PERSONAL_ONLY_EVENTS = ['task_reminder', 'report_available'];

const EVENT_PREFERENCES = ref<{ value: TelegramEventPreference; label: string }[]>([
    { value: 'support_sla_breach', label: 'Incumplimiento de SLA' },
    { value: 'automation_alert', label: 'Alerta de automatización' },
    { value: 'document_overdue', label: 'Documento vencido' },
]);

const eventDefinitions = ref<Record<string, TelegramEventDefinition>>({});

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

onMounted(() => {
    void load();
    void loadEvents();
});

async function loadEvents(): Promise<void> {
    try {
        const payload = await businessApi.telegram.events();
        const definitions = payload.events ?? {};

        eventDefinitions.value = definitions;

        EVENT_PREFERENCES.value = (payload.admin_available ?? payload.available ?? [])
            .filter((key) => {
                const definition = definitions[key];

                return definition !== undefined
                    && definition.available
                    && !PERSONAL_ONLY_EVENTS.includes(key);
            })
            .map((key) => ({
                value: key as TelegramEventPreference,
                label: definitions[key].label,
            }));
    } catch {
        // Maintain the administrative defaults if the catalogue cannot be
        // read: the screen must still let an operator rename or disable a
        // destination without pretending the catalogue was empty.
    }
}

function eventLabel(value: string): string {
    return EVENT_PREFERENCES.value.find((event) => event.value === value)?.label
        ?? eventDefinitions.value[value]?.label
        ?? value;
}

/**
 * Preferences stored on the row that the catalogue does not offer any more.
 *
 * They are shown, not dropped: an update sends them back unchanged unless the
 * operator deliberately edits that destination's available events.
 */
function storedUnavailable(endpoint: TelegramEndpoint): string[] {
    const offered = new Set(EVENT_PREFERENCES.value.map((event) => event.value));

    return (endpoint.event_preferences ?? []).filter((preference) => !offered.has(preference));
}

function storedUnavailableReason(value: string): string {
    return eventDefinitions.value[value]?.unavailable_reason
        ?? 'Este evento no se ofrece como suscripción nueva; el valor guardado se conserva.';
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
    // The chat id arrives masked from the API and is never sent back: the
    // server refuses a PATCH that carries it, and the form shows it read-only.
    form.chat_id = endpoint.chat_id;
    form.conversation_id = endpoint.conversation_id === null || endpoint.conversation_id === undefined
        ? ''
        : String(endpoint.conversation_id);
    form.enabled = endpoint.enabled;
    // Stored preferences are preserved as-is, including any historical value
    // the catalogue no longer offers.
    form.event_preferences = [...(endpoint.event_preferences ?? [])];
    formError.value = null;

    formOpen.value = true;
}

async function save(): Promise<void> {
    submitting.value = true;
    formError.value = null;

    const payload: {
        label: string;
        conversation_id: number | null;
        enabled: boolean;
        event_preferences: TelegramEventPreference[];
        chat_id?: string;
    } = {
        label: form.label,
        conversation_id: form.conversation_id === '' ? null : Number(form.conversation_id),
        enabled: form.enabled,
        // Includes any stored value the catalogue no longer offers, so an
        // unrelated edit does not silently drop it.
        event_preferences: form.event_preferences,
    };

    // The chat id is the identity of the destination. It is sent only when the
    // destination is created; the server refuses a PATCH that carries it.
    if (editing.value === null) {
        payload.chat_id = form.chat_id;
    }

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
                                class="cdh-badge"
                                :class="storedUnavailable(endpoint).includes(preference)
                                    ? 'cdh-badge--warning'
                                    : 'cdh-badge--info'"
                                :title="storedUnavailable(endpoint).includes(preference)
                                    ? storedUnavailableReason(preference)
                                    : undefined"
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
                    v-if="editing === null"
                    label="Chat ID"
                    name="telegram-chat-id"
                    v-model="form.chat_id"
                    required
                    hint="El chat al que el bot puede escribir."
                />

                <div v-else>
                    <label class="cdh-form-label" for="telegram-chat-id-readonly">Chat ID</label>
                    <input
                        id="telegram-chat-id-readonly"
                        class="form-control"
                        type="text"
                        :value="form.chat_id"
                        readonly
                    />
                    <p class="cdh-form-hint">
                        El chat de un destino no se cambia por edición. Para usar otro chat, cree un
                        destino nuevo y desactive este.
                    </p>
                </div>

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

                <AppAlert
                    v-if="editing !== null && storedUnavailable(editing).length > 0"
                    variant="warning"
                    title="Eventos guardados que ya no se ofrecen"
                >
                    <p class="mb-2">
                        Estos valores se conservan tal como están y se guardan de nuevo al editar
                        el destino; no se pueden volver a activar desde aquí.
                    </p>
                    <ul class="mb-0">
                        <li v-for="preference in storedUnavailable(editing)" :key="preference">
                            <strong>{{ eventLabel(preference) }}</strong>:
                            {{ storedUnavailableReason(preference) }}
                        </li>
                    </ul>
                </AppAlert>
            </form>
        </AppModal>
    </section>
</template>