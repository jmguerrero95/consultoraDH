<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppFormField from '@/components/ui/AppFormField.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import AppModal from '@/components/ui/AppModal.vue';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

import type {
    AutomationActionType,
    AutomationRule,
    AutomationRulePayload,
    AutomationTriggerType,
    AutomationVocabulary,
    VocabularyField,
} from '@/types/api';

/**
 * The automation rules an administrator configures and switches on.
 *
 * ## Why the form is driven by the vocabulary
 *
 * The set of triggers, and the fields each one takes, are decided by the server
 * (`GET /api/automations/vocabulary`). Rendering them from there rather than
 * hard-coding a form per trigger means a trigger added on the server appears here
 * without this file being touched, and — more importantly — the labels and option
 * lists a rule is saved with are the same ones the server validates against.
 *
 * ## Why an active rule cannot be edited here
 *
 * The server refuses to edit an active rule (409) and says so: deactívela
 * primero. That is deliberate, because changing the shape of a running rule is
 * how half-applied edits happen. Rather than hiding the restriction, the edit
 * button explains it and offers the way out.
 */

const auth = useAuthStore();
const toast = useToastStore();

const canManage = computed(() => auth.can('automations.manage'));

const vocabulary = ref<AutomationVocabulary | null>(null);
const rules = ref<AutomationRule[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const triggerFilter = ref('');
const activeFilter = ref('');

const total = ref(0);
const lastPage = ref(1);
const page = ref(1);
const perPage = 25;

let requestId = 0;

async function load(): Promise<void> {
    const current = ++requestId;

    loading.value = true;
    error.value = null;

    try {
        const [payload, vocab] = await Promise.all([
            businessApi.automations.list({
                page: page.value,
                per_page: perPage,
                trigger_type: triggerFilter.value || undefined,
                active: activeFilter.value || undefined,
            }),
            businessApi.automations.vocabulary(),
        ]);

        if (current !== requestId) {
            return;
        }

        rules.value = payload.data;
        total.value = payload.total;
        lastPage.value = Math.max(1, payload.last_page);
        vocabulary.value = vocab;
    } catch (cause) {
        if (current !== requestId) {
            return;
        }

        rules.value = [];
        error.value = cause instanceof ApiError ? cause.message : 'No fue posible cargar las automatizaciones.';
    } finally {
        if (current === requestId) {
            loading.value = false;
        }
    }
}

watch([triggerFilter, activeFilter], () => {
    page.value = 1;
    void load();
});

watch(page, () => void load());

onMounted(load);

const isFiltered = computed(() => triggerFilter.value !== '' || activeFilter.value !== '');

function clearFilters(): void {
    triggerFilter.value = '';
    activeFilter.value = '';
}

// --- Labels ------------------------------------------------------------------

const TRIGGER_LABELS: Record<string, string> = {
    audit_action: 'Acción auditada',
    schedule: 'Programada',
    task_due: 'Vencimiento de tarea',
    document_request_due: 'Vencimiento de documento',
    receivable_overdue: 'Cobro vencido',
    support_sla_breach: 'Incumplimiento de SLA',
};

const ACTION_LABELS: Record<string, string> = {
    internal_notification: 'Notificación interna',
    create_task: 'Crear tarea',
    send_email: 'Enviar correo',
    send_telegram: 'Enviar Telegram',
    assign_support_queue: 'Asignar cola de soporte',
    assign_support_user: 'Asignar usuario de soporte',
    set_support_priority: 'Establecer prioridad',
};

function triggerLabel(type: string): string {
    return vocabulary.value?.triggers?.[type]?.label ?? TRIGGER_LABELS[type] ?? type;
}

function actionLabel(type: string): string {
    return vocabulary.value?.actions?.[type]?.label ?? ACTION_LABELS[type] ?? type;
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString('es-CO');
}

// --- Create and edit ---------------------------------------------------------

const formOpen = ref(false);
const editing = ref<AutomationRule | null>(null);
const submitting = ref(false);
const formError = ref<string | null>(null);

interface DraftAction {
    action_type: AutomationActionType;
    config: Record<string, unknown>;
}

const form = reactive({
    name: '',
    description: '',
    trigger_type: 'schedule' as AutomationTriggerType,
    trigger_config: {} as Record<string, unknown>,
    actions: [] as DraftAction[],
});

function availableActions(): AutomationActionType[] {
    return Object.keys(vocabulary.value?.actions ?? {}) as AutomationActionType[];
}

function triggerFields(): Record<string, VocabularyField> {
    return vocabulary.value?.triggers?.[form.trigger_type]?.fields ?? {};
}

function actionFields(type: AutomationActionType): Record<string, VocabularyField> {
    return vocabulary.value?.actions?.[type]?.config ?? {};
}

/**
 * The vocabulary lists a plain list of values; the shared field component wants
 * them as value/label pairs. Converting here keeps that shape out of every call
 * site.
 */
function fieldOptions(field: VocabularyField): { value: string; label: string }[] | undefined {
    return field.options?.map((option) => ({ value: option, label: option }));
}

function openCreate(): void {
    editing.value = null;
    form.name = '';
    form.description = '';
    form.trigger_type = 'schedule';
    form.trigger_config = {};
    form.actions = [{ action_type: 'internal_notification', config: {} }];
    formError.value = null;

    formOpen.value = true;
}

function openEdit(rule: AutomationRule): void {
    editing.value = rule;
    form.name = rule.name;
    form.description = rule.description ?? '';
    form.trigger_type = rule.trigger_type;
    form.trigger_config = { ...rule.trigger_config };
    form.actions = (rule.actions ?? []).map((action) => ({
        action_type: action.action_type,
        config: { ...action.config },
    }));
    formError.value = null;

    formOpen.value = true;
}

/**
 * Coerce a raw field into the type the vocabulary declares.
 *
 * The inputs are text; `days_before` has to be sent as a number or the server
 * compares the string to an integer and finds nothing.
 */
function castField(field: VocabularyField, raw: unknown): unknown {
    if (raw === '' || raw === null || raw === undefined) {
        return null;
    }

    return field.type === 'integer' ? Number(raw) : raw;
}

function payload(): AutomationRulePayload {
    return {
        name: form.name,
        description: form.description === '' ? null : form.description,
        trigger_type: form.trigger_type,
        trigger_config: Object.fromEntries(
            Object.entries(form.trigger_config)
                .filter(([, value]) => value !== null && value !== '')
                .map(([key, value]) => [key, castField(triggerFields()[key] ?? { type: 'string' }, value)]),
        ),
        // A schedule trigger takes no conditions: the server refuses them, and
        // sending the ones a copied rule may still carry would be refused too.
        condition_config: form.trigger_type === 'schedule' ? [] : [],
        actions: form.actions.map((action, index) => ({
            action_type: action.action_type,
            config: Object.fromEntries(
                Object.entries(action.config)
                    .filter(([, value]) => value !== null && value !== '')
                    .map(([key, value]) => [
                        key,
                        castField(actionFields(action.action_type)[key] ?? { type: 'string' }, value),
                    ]),
            ),
            position: index,
        })),
    };
}

async function save(): Promise<void> {
    submitting.value = true;
    formError.value = null;

    try {
        if (editing.value === null) {
            await businessApi.automations.create(payload());
            toast.success('Regla creada');
        } else {
            await businessApi.automations.update(editing.value.id, payload());
            toast.success('Regla actualizada');
        }

        formOpen.value = false;
        await load();
    } catch (cause) {
        formError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible guardar la regla.';
    } finally {
        submitting.value = false;
    }
}

function addAction(): void {
    form.actions.push({ action_type: 'internal_notification', config: {} });
}

function removeAction(index: number): void {
    form.actions.splice(index, 1);
}

// --- Activation --------------------------------------------------------------

const busyRuleId = ref<number | null>(null);

async function toggleActive(rule: AutomationRule): Promise<void> {
    busyRuleId.value = rule.id;

    try {
        if (rule.active) {
            await businessApi.automations.deactivate(rule.id);
            toast.success('Regla desactivada');
        } else {
            await businessApi.automations.activate(rule.id);
            toast.success('Regla activada');
        }

        await load();
    } catch (cause) {
        toast.error(cause instanceof ApiError ? cause.message : 'No fue posible cambiar el estado.');
    } finally {
        busyRuleId.value = null;
    }
}
</script>

<template>
    <section class="cdh-content">
        <header class="cdh-page-header">
            <div>
                <h1 class="cdh-page-header__title">Automatizaciones</h1>
                <p class="cdh-page-header__subtitle">
                    {{ total }} {{ total === 1 ? 'regla configurada' : 'reglas configuradas' }}
                </p>
            </div>

            <AppButton v-if="canManage" icon="bi-plus-lg" @click="openCreate">Nueva regla</AppButton>
        </header>

        <AppAlert
            v-if="!canManage"
            variant="info"
            title="Consulta de solo lectura. Su rol no permite crear ni modificar automatizaciones."
            class="mb-4"
        />

        <AppAlert v-if="error" variant="danger" :title="error" class="mb-4" />

        <div class="cdh-filters" role="search">
            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="automations-trigger">Disparador</label>
                <select id="automations-trigger" v-model="triggerFilter" class="form-control form-control-sm">
                    <option value="">Todos</option>
                    <option
                        v-for="(definition, type) in vocabulary?.triggers ?? {}"
                        :key="type"
                        :value="type"
                    >
                        {{ definition.label }}
                    </option>
                </select>
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="automations-active">Estado</label>
                <select id="automations-active" v-model="activeFilter" class="form-control form-control-sm">
                    <option value="">Todas</option>
                    <option value="1">Activas</option>
                    <option value="0">Inactivas</option>
                </select>
            </div>

            <button v-if="isFiltered" type="button" class="cdh-link" @click="clearFilters">
                Limpiar filtros
            </button>
        </div>

        <AppLoading v-if="loading && rules.length === 0" label="Cargando automatizaciones" />

        <AppEmptyState
            v-else-if="!error && rules.length === 0"
            :title="isFiltered ? 'Sin coincidencias' : 'No hay automatizaciones configuradas'"
            :description="
                isFiltered
                    ? 'Pruebe con otro filtro.'
                    : 'Una regla se activa cuando su disparador ocurre y ejecuta sus acciones en orden.'
            "
            :action-label="isFiltered ? 'Limpiar filtros' : canManage ? 'Nueva regla' : undefined"
            @action="isFiltered ? clearFilters() : openCreate()"
        />

        <template v-else>
            <div class="cdh-table-wrap">
                <table class="cdh-table">
                    <caption class="cdh-visually-hidden">Reglas de automatización configuradas</caption>
                    <thead>
                        <tr>
                            <th scope="col">Regla</th>
                            <th scope="col">Disparador</th>
                            <th scope="col">Acciones</th>
                            <th scope="col">Próxima ejecución</th>
                            <th scope="col">Estado</th>
                            <th v-if="canManage" scope="col"><span class="cdh-visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="rule in rules" :key="rule.id">
                            <td>
                                <strong>{{ rule.name }}</strong>
                                <p v-if="rule.description" class="cdh-text-muted">{{ rule.description }}</p>
                            </td>
                            <td>
                                <span class="cdh-badge">{{ triggerLabel(rule.trigger_type) }}</span>
                            </td>
                            <td>
                                <span v-for="action in rule.actions ?? []" :key="action.id" class="cdh-badge cdh-badge--info">
                                    {{ actionLabel(action.action_type) }}
                                </span>
                                <span v-if="(rule.actions ?? []).length === 0" class="cdh-text-muted">Sin acciones</span>
                            </td>
                            <td>
                                <span v-if="rule.next_run_at">{{ formatDate(rule.next_run_at) }}</span>
                                <span v-else class="cdh-text-muted">—</span>
                            </td>
                            <td>
                                <span
                                    class="cdh-badge"
                                    :class="rule.active ? 'cdh-badge--success' : 'cdh-badge--neutral'"
                                >
                                    {{ rule.active ? 'Activa' : 'Inactiva' }}
                                </span>
                            </td>
                            <td v-if="canManage" class="cdh-table__actions">
                                <AppButton
                                    variant="link"
                                    @click="toggleActive(rule)"
                                    :disabled="busyRuleId === rule.id"
                                >
                                    {{ rule.active ? 'Desactivar' : 'Activar' }}
                                </AppButton>
                                <AppButton variant="link" :disabled="rule.active" @click="openEdit(rule)">
                                    Editar
                                </AppButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="lastPage > 1" class="cdh-pagination" aria-label="Paginación de automatizaciones">
                <AppButton variant="link" :disabled="page <= 1" @click="page -= 1">Anterior</AppButton>
                <span>Página {{ page }} de {{ lastPage }}</span>
                <AppButton variant="link" :disabled="page >= lastPage" @click="page += 1">Siguiente</AppButton>
            </nav>
        </template>

        <AppModal
            :open="formOpen"
            :title="editing === null ? 'Nueva regla de automatización' : 'Editar regla de automatización'"
            confirm-label="Guardar"
            :busy="submitting"
            @confirm="save"
            @cancel="formOpen = false"
        >
            <p v-if="editing !== null && editing.active" class="cdh-form-hint">
                Para editar una regla activa primero debe desactivarla.
            </p>

            <AppAlert v-if="formError" variant="danger" :title="formError" class="mb-3" />

            <form class="cdh-form" @submit.prevent>
                <AppFormField
                    label="Nombre"
                    name="rule-name"
                    v-model="form.name"
                    required
                    :disabled="editing !== null && editing.active"
                />

                <AppFormField
                    label="Descripción"
                    name="rule-description"
                    v-model="form.description"
                    :disabled="editing !== null && editing.active"
                />

                <div class="cdh-stack-4">
                    <label class="cdh-form-label" for="rule-trigger">Disparador</label>
                    <select
                        id="rule-trigger"
                        v-model="form.trigger_type"
                        class="form-control"
                        :disabled="editing !== null && editing.active"
                    >
                        <option
                            v-for="(definition, type) in vocabulary?.triggers ?? {}"
                            :key="type"
                            :value="type"
                        >
                            {{ definition.label }}
                        </option>
                    </select>
                </div>

                <fieldset class="cdh-card cdh-card__body">
                    <legend class="cdh-form-label">Configuración del disparador</legend>

                    <p v-if="Object.keys(triggerFields()).length === 0" class="cdh-form-hint">
                        Este disparador no requiere configuración adicional.
                    </p>

                    <AppFormField
                        v-for="(field, key) in triggerFields()"
                        :key="key"
                        :label="field.label"
                        :name="`trigger-${key}`"
                        :model-value="String(form.trigger_config[key] ?? '')"
                        :options="fieldOptions(field)"
                        :disabled="editing !== null && editing.active"
                        @update:model-value="form.trigger_config[key] = $event"
                    />
                </fieldset>

                <fieldset class="cdh-card cdh-card__body">
                    <legend class="cdh-form-label">Acciones</legend>

                    <p v-if="form.trigger_type === 'schedule'" class="cdh-form-hint">
                        Un disparador programado no admite condiciones dinámicas.
                    </p>

                    <div
                        v-for="(action, index) in form.actions"
                        :key="index"
                        class="cdh-card cdh-card__body"
                    >
                        <div class="cdh-stack-4">
                            <label class="cdh-form-label" :for="`action-type-${index}`">Acción</label>
                            <select
                                :id="`action-type-${index}`"
                                v-model="action.action_type"
                                class="form-control"
                                :disabled="editing !== null && editing.active"
                            >
                                <option
                                    v-for="type in availableActions()"
                                    :key="type"
                                    :value="type"
                                >
                                    {{ actionLabel(type) }}
                                </option>
                            </select>
                        </div>

                        <AppFormField
                            v-for="(field, key) in actionFields(action.action_type)"
                            :key="key"
                            :label="field.label"
                            :name="`action-${index}-${key}`"
                            :model-value="String(action.config[key] ?? '')"
                            :options="fieldOptions(field)"
                            :disabled="editing !== null && editing.active"
                            @update:model-value="action.config[key] = $event"
                        />

                        <button
                            type="button"
                            class="cdh-link"
                            :disabled="form.actions.length === 1"
                            @click="removeAction(index)"
                        >
                            Quitar acción
                        </button>
                    </div>

                    <AppButton
                        icon="bi-plus-lg"
                        variant="secondary"
                        :disabled="editing !== null && editing.active"
                        @click="addAction"
                    >
                        Añadir acción
                    </AppButton>
                </fieldset>
            </form>
        </AppModal>
    </section>
</template>