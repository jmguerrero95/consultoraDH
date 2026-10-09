<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import ClientUpdateRequestsPanel from '@/components/clients/ClientUpdateRequestsPanel.vue';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppFormField from '@/components/ui/AppFormField.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import AppModal from '@/components/ui/AppModal.vue';
import { useDebouncedRef } from '@/composables/useDebouncedRef';
import { pesos } from '@/composables/useFormatters';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

import type {
    ClientAccountPayload,
    Affiliation,
    Assignment,
    ClientDetailPayload,
    CompanySummary,
    ConflictPayload,
    DataQualityFinding,
    ResolutionOption,
    SocialSecurityEntity,
    SocialSecurityType,
} from '@/types/api';

/**
 * The client record: who they are, where they work, what they are affiliated to,
 * and what has happened to the record over time.
 *
 * The tab structure exists because this is four different questions, and the
 * answer to one is not the answer to another. Someone checking a person's EPS
 * does not need their address, and someone auditing a change does not need a form.
 *
 * Every mutation that can be irreversible is behind a confirmation that says what
 * it will do, not just "are you sure".
 */

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const toast = useToastStore();

const id = computed(() => Number(route.params.id));

const payload = ref<ClientDetailPayload | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const busy = ref(false);

const canUpdate = computed(() => auth.can('clients.update'));
const canChangeStatus = computed(() => auth.can('clients.change_status'));
const canManageRelationships = computed(() => auth.can('relationships.manage'));
const canManageAffiliations = computed(() => auth.can('affiliations.manage'));
const canSeeAffiliations = computed(() => auth.can('affiliations.view'));

const TABS = [
    { id: 'resumen', label: 'Resumen' },
    { id: 'cuenta', label: 'Cuenta' },
    { id: 'empresas', label: 'Empresas' },
    { id: 'afiliaciones', label: 'Afiliaciones' },
    { id: 'historial', label: 'Historial' },
] as const;

type TabId = (typeof TABS)[number]['id'];

const tab = ref<TabId>('resumen');

/** ISO dates as yyyy-mm-dd; anything else is shown as it was received. */
function formatDate(value: string | null): string {
    if (value === null || value === '') {
        return '—';
    }

    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime())
        ? value
        : parsed.toLocaleDateString('es-CO', { year: 'numeric', month: '2-digit', day: '2-digit' });
}

function formatMoment(value: string): string {
    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime())
        ? value
        : parsed.toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' });
}

/** Turns `affiliation.closed` into something a person can read. */
function humanAction(action: string): string {
    return (
        {
            // Dotted, exactly as `AuditAction` spells them. The keys used to be
            // underscored, so nothing matched and the timeline showed the raw
            // codes to whoever was looking at the client's history.
            'client.created': 'Cliente creado',
            'client.updated': 'Datos actualizados',
            'client.activated': 'Cliente reactivado',
            'client.deactivated': 'Cliente desactivado',
            'company.created': 'Empresa creada',
            'company.updated': 'Empresa actualizada',
            'company.activated': 'Empresa reactivada',
            'company.deactivated': 'Empresa desactivada',
            'relationship.created': 'Relación con empresa creada',
            'relationship.closed': 'Relación con empresa cerrada',
            'relationship.transferred': 'Cliente transferido de empresa',
            'relationship.parallel_authorized': 'Relación en paralelo autorizada',
            'affiliation.created': 'Afiliación registrada',
            'affiliation.closed': 'Afiliación cerrada',
            'affiliation.changed': 'Afiliación cambiada de entidad',
            'social_security_entity.created': 'Entidad registrada',
            'social_security_entity.updated': 'Entidad actualizada',
            'social_security_entity.deactivated': 'Entidad desactivada',
        }[action] ?? action
    );
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        payload.value = await businessApi.clients.show(id.value);
    } catch (cause) {
        error.value = cause instanceof ApiError ? cause.message : 'No fue posible cargar la ficha.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);

// --- Status ------------------------------------------------------------------

const statusOpen = ref(false);
const statusEffectiveDate = ref(new Date().toISOString().slice(0, 10));
const statusChoice = ref<'block' | 'close'>('block');
const statusConflict = ref<ConflictPayload | null>(null);

function requestStatusChange(to: 'active' | 'inactive'): void {
    statusConflict.value = null;
    statusChoice.value = 'block';
    statusEffectiveDate.value = new Date().toISOString().slice(0, 10);

    if (to === 'active') {
        void applyStatus('active', 'block');
        return;
    }

    statusOpen.value = true;
}

async function applyStatus(
    status: 'active' | 'inactive',
    when: 'block' | 'close',
): Promise<void> {
    busy.value = true;

    try {
        await businessApi.clients.changeStatus(id.value, {
            status,
            when,
            effective_date: when === 'close' ? statusEffectiveDate.value : null,
        });

        statusOpen.value = false;
        statusConflict.value = null;

        toast.success(status === 'active' ? 'Cliente reactivado.' : 'Cliente desactivado.');

        await load();
    } catch (cause) {
        if (cause instanceof ApiError && cause.status === 409) {
            // The client still has open relationships. The server says how many
            // and offers the alternative, so the dialog becomes that choice
            // instead of a dead end.
            statusConflict.value = cause.payload as ConflictPayload;
        } else {
            toast.error(cause instanceof ApiError ? cause.message : 'No fue posible cambiar el estado.');
        }
    } finally {
        busy.value = false;
    }
}

// --- Relationships -----------------------------------------------------------

const companies = ref<CompanySummary[]>([]);

/**
 * The company picker is searchable, and has to be.
 *
 * The endpoint answers with a bounded page, because returning every company of
 * a large portfolio would be worse. A fixed list would then quietly hide the
 * companies that sort past the limit, and someone linking a client would be told
 * the company does not exist. Searching moves the limit out of the way: the page
 * is bounded, the answer is not.
 */
const companySearch = ref('');
const debouncedCompanySearch = useDebouncedRef(companySearch);

const linkOpen = ref(false);
const linkConflict = ref<ConflictPayload | null>(null);
const linkForm = reactive({
    company_id: '',
    started_on: new Date().toISOString().slice(0, 10),
    job_title: '',
    notes: '',
    resolution: 'only_if_none',
    parallel_reason: '',
});

async function loadCompanies(): Promise<void> {
    const search = debouncedCompanySearch.value.trim();

    try {
        // `?? []` for the same reason as the affiliation picker: an unexpected body
        // must leave an empty picker, not put `undefined` where a list is rendered.
        companies.value = (
            await businessApi.clients.companyOptions(search === '' ? undefined : search)
        ).companies ?? [];
    } catch {
        companies.value = [];
    }
}

// A fresh search replaces the list, and refreshes while the dialog is open.
watch(debouncedCompanySearch, () => {
    if (linkOpen.value || transferTarget.value !== null) {
        void loadCompanies();
    }
});

function openCompanySearch(): void {
    companySearch.value = '';
}

function openLink(): void {
    linkForm.company_id = '';
    linkForm.started_on = new Date().toISOString().slice(0, 10);
    linkForm.job_title = '';
    linkForm.notes = '';
    linkForm.resolution = 'only_if_none';
    linkForm.parallel_reason = '';
    linkConflict.value = null;

    linkOpen.value = true;
    openCompanySearch();

    // Filled when the dialog opens rather than with the rest of the page: the
    // picker only matters at that moment, and fetching it on every visit would
    // be a request nobody uses most of the time.
    void loadCompanies();
}

const needsReason = computed(() => linkForm.resolution === 'parallel');

async function submitLink(): Promise<void> {
    busy.value = true;

    try {
        await businessApi.relationships.link(id.value, {
            company_id: Number(linkForm.company_id),
            started_on: linkForm.started_on,
            job_title: linkForm.job_title === '' ? null : linkForm.job_title,
            notes: linkForm.notes === '' ? null : linkForm.notes,
            resolution: linkForm.resolution,
            parallel_reason: linkForm.parallel_reason === '' ? null : linkForm.parallel_reason,
        });

        linkOpen.value = false;
        toast.success('Relación registrada.');

        await load();
    } catch (cause) {
        if (cause instanceof ApiError && cause.status === 409) {
            const payload = cause.payload as { code?: string; message?: string; options?: ConflictPayload['options'] };

            if (payload?.code === 'transfer_source_required') {
                // Several open relationships: the server will not choose one. Say so
                // and point at the action that does, on the row itself.
                linkOpen.value = false;
                toast.error(
                    payload.message ??
                        'El cliente tiene varias relaciones abiertas. Elija en la lista cuál transfiere.',
                );
                await load();
                return;
            }

            linkConflict.value = cause.payload as ConflictPayload;
        } else {
            toast.error(cause instanceof ApiError ? cause.message : 'No fue posible registrar la relación.');
        }
    } finally {
        busy.value = false;
    }
}

/**
 * Applying one of the three choices the server offered.
 *
 * "Cancelar" simply closes the dialog and changes nothing, which is the honest
 * implementation of a choice whose description is "no modifica nada".
 */
async function applyResolution(option: ResolutionOption): Promise<void> {
    if (option.value === 'cancel') {
        linkConflict.value = null;
        linkOpen.value = false;
        return;
    }

    linkForm.resolution = option.value;
    linkConflict.value = null;

    await submitLink();
}

const closeTarget = ref<Assignment | null>(null);
const closeDate = ref(new Date().toISOString().slice(0, 10));
const closeReason = ref('');

function openClose(assignment: Assignment): void {
    closeTarget.value = assignment;
    closeDate.value = new Date().toISOString().slice(0, 10);
    closeReason.value = '';
}

async function confirmClose(): Promise<void> {
    if (closeTarget.value === null) {
        return;
    }

    busy.value = true;

    try {
        await businessApi.relationships.close(closeTarget.value.id, {
            ended_on: closeDate.value,
            reason: closeReason.value === '' ? null : closeReason.value,
        });

        closeTarget.value = null;
        toast.success('Relación cerrada.');

        await load();
    } catch (cause) {
        toast.error(cause instanceof ApiError ? cause.message : 'No fue posible cerrar la relación.');
    } finally {
        busy.value = false;
    }
}

const transferTarget = ref<Assignment | null>(null);
const transferForm = reactive({
    to_company_id: '',
    effective_on: new Date().toISOString().slice(0, 10),
    job_title: '',
});

function openTransfer(assignment: Assignment): void {
    transferTarget.value = assignment;
    transferForm.to_company_id = '';
    transferForm.effective_on = new Date().toISOString().slice(0, 10);
    transferForm.job_title = assignment.job_title ?? '';
    openCompanySearch();

    // The destination picker needs the same list the link dialog uses. Loaded
    // here as well, because arriving through the link dialog cannot be assumed:
    // a transfer is usually the first thing someone does on this page.
    void loadCompanies();
}

async function confirmTransfer(): Promise<void> {
    if (transferTarget.value === null) {
        return;
    }

    busy.value = true;

    try {
        await businessApi.relationships.transfer(transferTarget.value.id, {
            to_company_id: Number(transferForm.to_company_id),
            effective_on: transferForm.effective_on,
            job_title: transferForm.job_title === '' ? null : transferForm.job_title,
        });

        transferTarget.value = null;
        toast.success('Cliente transferido. La relación anterior quedó en el historial.');

        await load();
    } catch (cause) {
        toast.error(cause instanceof ApiError ? cause.message : 'No fue posible transferir el cliente.');
    } finally {
        busy.value = false;
    }
}

// --- Affiliations ------------------------------------------------------------

const AFFILIATION_TYPES: { value: SocialSecurityType; label: string }[] = [
    { value: 'EPS', label: 'EPS' },
    { value: 'AFP', label: 'AFP' },
    { value: 'ARL', label: 'ARL' },
    { value: 'CCF', label: 'Caja de Compensación Familiar' },
];

/**
 * The risk levels the server sent, in the shape the form field wants.
 *
 * Deliberately not written down here. The enum `ArlRiskClass` is the single
 * definition of what the five classes mean, and this list used to disagree with it
 * about class V.
 */
const riskLevels = computed(() =>
    (payload.value?.risk_options ?? []).map((option) => ({
        value: String(option.value),
        label: option.label,
    })),
);

const entityOptions = ref<SocialSecurityEntity[]>([]);
const affiliationOpen = ref(false);
const affiliationConflict = ref<ConflictPayload | null>(null);
const affiliationForm = reactive({
    social_security_entity_id: '',
    type: 'EPS' as SocialSecurityType,
    started_on: new Date().toISOString().slice(0, 10),
    arl_risk_class: '',
    notes: '',
    replace_current: false,
    effective_date: new Date().toISOString().slice(0, 10),
});

async function loadEntities(): Promise<void> {
    try {
        const list = await businessApi.entities.list({ per_page: 100, status: 'active' });

        // Guarded: a response that is not the list this expects (an error body, or a
        // role that may not read the catalogue) would otherwise put `undefined` in
        // the ref, and the computed below would throw while rendering. An empty
        // picker is a screen with nothing to choose; a thrown render is a broken one.
        entityOptions.value = list.entities ?? [];
    } catch {
        entityOptions.value = [];
    }
}

function openAffiliation(): void {
    affiliationForm.social_security_entity_id = '';
    affiliationForm.type = 'EPS';
    affiliationForm.started_on = new Date().toISOString().slice(0, 10);
    affiliationForm.arl_risk_class = '';
    affiliationForm.notes = '';
    affiliationForm.replace_current = false;
    affiliationForm.effective_date = new Date().toISOString().slice(0, 10);
    affiliationConflict.value = null;

    affiliationOpen.value = true;
    void loadEntities();
}

/** Only an ARL may carry a risk level, so the field appears only for an ARL. */
const isArl = computed(() => affiliationForm.type === 'ARL');

const entityChoices = computed(() =>
    entityOptions.value
        .filter((entity) => entity.type === affiliationForm.type)
        .map((entity) => ({ value: String(entity.id), label: entity.name })),
);

async function submitAffiliation(): Promise<void> {
    busy.value = true;

    try {
        await businessApi.affiliations.create(id.value, {
            social_security_entity_id: Number(affiliationForm.social_security_entity_id),
            type: affiliationForm.type,
            started_on: affiliationForm.started_on,
            arl_risk_class: isArl.value && affiliationForm.arl_risk_class !== ''
                ? Number(affiliationForm.arl_risk_class)
                : null,
            notes: affiliationForm.notes === '' ? null : affiliationForm.notes,
            replace_current: affiliationForm.replace_current,
            effective_date: affiliationForm.replace_current ? affiliationForm.effective_date : null,
        });

        affiliationOpen.value = false;
        toast.success('Afiliación registrada.');

        await load();
    } catch (cause) {
        if (cause instanceof ApiError && cause.status === 409) {
            affiliationConflict.value = cause.payload as ConflictPayload;
        } else {
            toast.error(cause instanceof ApiError ? cause.message : 'No fue posible registrar la afiliación.');
        }
    } finally {
        busy.value = false;
    }
}

async function applyAffiliationChoice(option: ResolutionOption): Promise<void> {
    if (option.value === 'cancel') {
        affiliationConflict.value = null;
        affiliationOpen.value = false;
        return;
    }

    affiliationForm.replace_current = true;
    affiliationConflict.value = null;

    await submitAffiliation();
}

const closeAffiliationTarget = ref<Affiliation | null>(null);
const closeAffiliationDate = ref(new Date().toISOString().slice(0, 10));

function openCloseAffiliation(affiliation: Affiliation): void {
    closeAffiliationTarget.value = affiliation;
    closeAffiliationDate.value = new Date().toISOString().slice(0, 10);
}

async function confirmCloseAffiliation(): Promise<void> {
    if (closeAffiliationTarget.value === null) {
        return;
    }

    busy.value = true;

    try {
        await businessApi.affiliations.close(closeAffiliationTarget.value.id, {
            ended_on: closeAffiliationDate.value,
        });

        closeAffiliationTarget.value = null;
        toast.success('Afiliación cerrada.');

        await load();
    } catch (cause) {
        toast.error(cause instanceof ApiError ? cause.message : 'No fue posible cerrar la afiliación.');
    } finally {
        busy.value = false;
    }
}

const warnings = computed<DataQualityFinding[]>(() => payload.value?.data_quality ?? []);

const activeAffiliations = computed<Affiliation[]>(() => payload.value?.affiliations.active ?? []);
const historyAffiliations = computed<Affiliation[]>(() => payload.value?.affiliations.history ?? []);
const maySeeCompanies = computed(() => payload.value?.companies.visible === true);
const maySeeAffiliations = computed(() => payload.value?.affiliations.visible === true);

/**
 * The sections this role may read.
 *
 * A tab for a section the role cannot read would open onto an answer the server
 * refuses to give, so the tab is not offered. The permission is the server's; this
 * only avoids sending somebody to a screen that says "you cannot see this".
 */
const maySeeFinancials = computed(() => auth.can('receivables.view'));

/**
 * The debt summary, loaded only when the tab is actually shown.
 *
 * A person who never opens the Cuenta tab does not need a financial request made on
 * their behalf, and a Support user who cannot read it never triggers one that would
 * come back 403.
 */
const financials = ref<ClientAccountPayload | null>(null);
const loadingFinancials = ref(false);
const financialsError = ref<string | null>(null);
let financialsRequest = 0;

async function loadFinancials(): Promise<void> {
    const current = ++financialsRequest;

    loadingFinancials.value = true;
    financialsError.value = null;

    try {
        const payload = await businessApi.receivables.clientAccount(Number(id));

        if (current !== financialsRequest) {
            return;
        }

        financials.value = payload;
    } catch (cause) {
        if (current !== financialsRequest) {
            return;
        }

        financials.value = null;
        financialsError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible cargar la cuenta.';
    } finally {
        if (current === financialsRequest) {
            loadingFinancials.value = false;
        }
    }
}

watch([tab, maySeeFinancials], ([selected, permitted]) => {
    if (selected === 'cuenta' && permitted && financials.value === null) {
        void loadFinancials();
    }
});

const visibleTabs = computed(() =>
    TABS.filter((entry) => {
        if (entry.id === 'empresas') {
            return maySeeCompanies.value;
        }

        if (entry.id === 'afiliaciones') {
            return maySeeAffiliations.value;
        }

        // What the client owes is a separate permission from what the directory
        // knows about them. Support reads a client's situation without holding
        // authority over the money, and Collections reads the debt without needing
        // the whole directory.
        if (entry.id === 'cuenta') {
            return maySeeFinancials.value;
        }

        return true;
    }),
);

const activeCompanies = computed<Assignment[]>(() => payload.value?.companies.active ?? []);
const historyCompanies = computed<Assignment[]>(() => payload.value?.companies.history ?? []);


</script>

<template>
    <section class="cdh-content">
        <AppLoading v-if="loading" label="Cargando ficha" />

        <AppAlert v-else-if="error" variant="danger" :title="error" class="mb-4">
            <AppButton variant="secondary" @click="router.push({ name: 'clients' })">
                Volver a clientes
            </AppButton>
        </AppAlert>

        <template v-else-if="payload">
            <div class="cdh-record-header">
                <div class="cdh-record-header__identity">
                    <h1 class="cdh-page-header__title mb-0">{{ payload.client.full_name }}</h1>
                    <p class="cdh-record-header__meta">
                        <span class="cdh-mono">{{ payload.client.document_label }}</span>
                        <span
                            class="cdh-badge"
                            :class="payload.client.status === 'active' ? 'cdh-badge--success' : 'cdh-badge--neutral'"
                        >
                            {{ payload.client.status_label }}
                        </span>
                    </p>
                </div>

                <div class="d-flex gap-2">
                    <AppButton
                        v-if="canUpdate"
                        variant="secondary"
                        icon="bi-pencil"
                        :to="{ name: 'clients.edit', params: { id } }"
                        >
Editar
</AppButton
                    >
                    <AppButton
                        v-if="canChangeStatus"
                        :variant="payload.client.status === 'active' ? 'secondary' : 'primary'"
                        @click="requestStatusChange(payload.client.status === 'active' ? 'inactive' : 'active')"
                    >
                        {{ payload.client.status === 'active' ? 'Desactivar' : 'Reactivar' }}
                    </AppButton>
                </div>
            </div>

            <div class="cdh-tabs" role="tablist" aria-label="Secciones de la ficha">
                <button
                    v-for="entry in visibleTabs"
                    :id="`tab-${entry.id}`"
                    :key="entry.id"
                    type="button"
                    role="tab"
                    class="cdh-tab"
                    :aria-selected="tab === entry.id"
                    :aria-controls="`panel-${entry.id}`"
                    @click="tab = entry.id"
                >
                    {{ entry.label }}
                </button>
            </div>

            <!-- Resumen -->
            <div v-show="tab === 'resumen'" id="panel-resumen" role="tabpanel" aria-labelledby="tab-resumen">
                <div class="cdh-grid-2">
                    <article class="cdh-card">
                        <h2 class="cdh-card__title">Datos de contacto</h2>
                        <div class="cdh-card__body">
                            <dl class="cdh-status-list">
                                <div class="cdh-status-row">
                                    <dt class="cdh-status-row__label">Correo</dt>
                                    <dd class="cdh-status-row__value mb-0">{{ payload.client.email ?? '—' }}</dd>
                                </div>
                                <div class="cdh-status-row">
                                    <dt class="cdh-status-row__label">Teléfono</dt>
                                    <dd class="cdh-status-row__value mb-0">{{ payload.client.phone ?? '—' }}</dd>
                                </div>
                                <div class="cdh-status-row">
                                    <dt class="cdh-status-row__label">Dirección</dt>
                                    <dd class="cdh-status-row__value mb-0">{{ payload.client.address ?? '—' }}</dd>
                                </div>
                                <div class="cdh-status-row">
                                    <dt class="cdh-status-row__label">Ciudad</dt>
                                    <dd class="cdh-status-row__value mb-0">
                                        {{ payload.client.city ?? '—' }}<span v-if="payload.client.department">
                                            , {{ payload.client.department }}</span
                                        >
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </article>

                    <article v-if="maySeeCompanies" class="cdh-card">
                        <h2 class="cdh-card__title">Empresas actuales</h2>
                        <div class="cdh-card__body">
                            <p v-if="activeCompanies.length === 0" class="cdh-text-muted mb-0">
                                Sin empresas vinculadas.
                            </p>
                            <ul v-else class="cdh-status-list mb-0">
                                <li
                                    v-for="assignment in activeCompanies"
                                    :key="assignment.id"
                                    class="cdh-status-row"
                                >
                                    <span class="cdh-status-row__label">
                                        {{ assignment.company?.display_name ?? 'Empresa' }}
                                    </span>
                                    <span class="cdh-status-row__value mb-0">
                                        {{ assignment.job_title ?? 'Sin cargo' }} ·
                                        desde {{ formatDate(assignment.started_on) }}
                                        <span v-if="assignment.is_parallel" class="cdh-badge cdh-badge--info">
                                            En paralelo
                                        </span>
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </article>
                </div>

                <article v-if="maySeeAffiliations" class="cdh-card mt-3">
                    <h2 class="cdh-card__title">Afiliaciones actuales</h2>
                    <div class="cdh-card__body">
                        <p v-if="activeAffiliations.length === 0" class="cdh-text-muted mb-0">
                            Sin afiliaciones registradas.
                        </p>
                        <div v-else class="cdh-grid-3">
                            <div v-for="type in AFFILIATION_TYPES" :key="type.value">
                                <p class="cdh-text-xs cdh-text-muted mb-1">{{ type.label }}</p>
                                <p class="mb-0">
                                    <template v-if="activeAffiliations.find((a) => a.type === type.value)">
                                        {{
                                            activeAffiliations.find((a) => a.type === type.value)?.entity?.name
                                        }}
                                        <span
                                            v-if="
                                                activeAffiliations.find((a) => a.type === type.value)?.arl_risk_label
                                            "
                                            class="cdh-badge cdh-badge--warning"
                                        >
                                            {{
                                                activeAffiliations.find((a) => a.type === type.value)
                                                    ?.arl_risk_label
                                            }}
                                        </span>
                                    </template>
                                    <span v-else class="cdh-text-muted">—</span>
                                </p>
                            </div>
                        </div>
                    </div>
                </article>

                <article v-if="warnings.length > 0" class="cdh-card mt-3">
                    <h2 class="cdh-card__title">
                        Alertas de calidad de datos
                        <span class="cdh-badge cdh-badge--warning">{{ warnings.length }}</span>
                    </h2>
                    <div class="cdh-card__body">
                        <ul class="cdh-warnings">
                            <li
                                v-for="finding in warnings"
                                :key="finding.code"
                                class="cdh-warning"
                                :class="`cdh-warning--${finding.severity}`"
                            >
                                <span class="cdh-warning__message">
                                    <i
                                        class="bi"
                                        :class="
                                            finding.severity === 'error'
                                                ? 'bi-x-circle'
                                                : finding.severity === 'warning'
                                                  ? 'bi-exclamation-triangle'
                                                  : 'bi-info-circle'
                                        "
                                        aria-hidden="true"
                                    />
                                    {{ finding.message }}
                                </span>
                                <span v-if="finding.suggestion" class="cdh-warning__suggestion">
                                    {{ finding.suggestion }}
                                </span>
                            </li>
                        </ul>
                    </div>
                </article>
            </div>

            <!--
                A05-R1 §3: proposals the client made about their own data. It lives here
                because a proposal is about one client, and a separate screen would make a
                reviewer open two pages to answer one question.
            -->
            <ClientUpdateRequestsPanel
                v-if="payload.client"
                :client-id="payload.client.id"
            />

            <!--
                Cuenta: what this client owes. A summary rather than the whole
                statement, because the statement is a screen of its own and the ficha
                is about who somebody is rather than what they owe.
            -->
            <div
                v-if="maySeeFinancials"
                v-show="tab === 'cuenta'"
                id="panel-cuenta"
                role="tabpanel"
                aria-labelledby="tab-cuenta"
            >
                <div v-if="financials === null" class="cdh-text-muted">
                    <AppLoading v-if="loadingFinancials" label="Cargando la cuenta" />
                    <AppAlert
                        v-else-if="financialsError !== null"
                        variant="warning"
                        :title="financialsError"
                    />
                    <p v-else>Sin datos financieros.</p>
                </div>

                <template v-else>
                    <div class="cdh-grid-2 mb-3">
                        <article class="cdh-stat">
                            <p class="cdh-stat__label">
                                <i class="bi bi-cash-coin" aria-hidden="true" />
                                <span>Saldo pendiente</span>
                            </p>
                            <p class="cdh-stat__value">
                                {{ pesos(financials.summary.outstanding_balance_cop) }}
                            </p>
                            <p class="cdh-stat__hint">
                                {{ financials.summary.open_obligations_count }} obligaciones
                                abiertas
                            </p>
                        </article>

                        <article class="cdh-stat">
                            <p class="cdh-stat__label">
                                <i class="bi bi-exclamation-triangle" aria-hidden="true" />
                                <span>Vencido</span>
                            </p>
                            <p
                                class="cdh-stat__value"
                                :class="{ 'cdh-danger': financials.summary.overdue_balance_cop > 0 }"
                            >
                                {{ pesos(financials.summary.overdue_balance_cop) }}
                            </p>
                            <!-- §26: the reference date for aging, not a statement date. -->
                            <p class="cdh-stat__hint">
                                Antigüedad evaluada al {{ formatDate(financials.as_of) }}
                            </p>
                        </article>
                    </div>

                    <AppEmptyState
                        v-if="financials.obligations.length === 0"
                        title="Sin obligaciones"
                        description="Este cliente no tiene obligaciones generadas."
                    />

                    <div v-else class="cdh-table-wrap">
                        <table class="cdh-table">
                            <caption class="cdh-visually-hidden">
                                Obligaciones del cliente
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">Periodo</th>
                                    <th scope="col" class="cdh-table__wide">Empresa</th>
                                    <th scope="col">Total</th>
                                    <th scope="col">Pagado</th>
                                    <th scope="col">Saldo</th>
                                    <th scope="col">Vence</th>
                                    <th scope="col">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="obligation in financials.obligations" :key="obligation.id">
                                    <td data-label="Periodo">
                                        <span class="cdh-table__primary">
                                            {{ obligation.period_label }}
                                        </span>
                                    </td>
                                    <td data-label="Empresa" class="cdh-table__wide">
                                        {{ obligation.company_name }}
                                    </td>
                                    <td data-label="Total" class="cdh-table__numeric">
                                        {{ pesos(obligation.effective_amount_cop) }}
                                    </td>
                                    <td data-label="Pagado" class="cdh-table__numeric">
                                        {{ pesos(obligation.paid_amount_cop) }}
                                    </td>
                                    <td data-label="Saldo" class="cdh-table__numeric">
                                        {{ pesos(obligation.balance_cop) }}
                                    </td>
                                    <td data-label="Vence">
                                        <span :class="{ 'cdh-danger': obligation.is_overdue }">
                                            {{ formatDate(obligation.due_on) }}
                                        </span>
                                    </td>
                                    <td data-label="Estado">
                                        <span
                                            class="cdh-badge"
                                            :class="
                                                obligation.settlement_state === 'paid'
                                                    ? 'cdh-badge--success'
                                                    : obligation.settlement_state === 'partial'
                                                      ? 'cdh-badge--warning'
                                                      : 'cdh-badge--neutral'
                                            "
                                        >
                                            {{ obligation.settlement_state_label }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-end mt-3">
                        <AppButton
                            variant="secondary"
                            :to="{ name: 'clients.account', params: { id } }"
                            icon="bi-list-check"
                        >
                            Ver la cuenta completa
                        </AppButton>
                    </div>
                </template>
            </div>

            <!-- Empresas -->
            <div
                v-if="maySeeCompanies"
                v-show="tab === 'empresas'"
                id="panel-empresas"
                role="tabpanel"
                aria-labelledby="tab-empresas"
            >
                <div class="d-flex justify-content-end mb-3">
                    <AppButton v-if="canManageRelationships" icon="bi-plus-lg" @click="openLink">
                        Vincular empresa
                    </AppButton>
                </div>

                <article class="cdh-card mb-3">
                    <h2 class="cdh-card__title">Relaciones abiertas</h2>
                    <div class="cdh-card__body cdh-card__body--flush">
                        <AppEmptyState
                            v-if="activeCompanies.length === 0"
                            title="Sin empresas abiertas"
                            description="Vincule el cliente a una empresa para registrar su relación laboral."
                            icon="bi-building"
                        />
                        <div v-else class="cdh-table-wrap">
                            <table class="cdh-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Empresa</th>
                                        <th scope="col">Cargo</th>
                                        <th scope="col">Inicio</th>
                                        <th scope="col">Estado</th>
                                        <th scope="col"><span class="cdh-visually-hidden">Acciones</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="assignment in activeCompanies" :key="assignment.id">
                                        <td data-label="Empresa">
                                            <span class="cdh-table__primary">
                                                {{ assignment.company?.display_name ?? '—' }}
                                            </span>
                                        </td>
                                        <td data-label="Cargo">{{ assignment.job_title ?? '—' }}</td>
                                        <td data-label="Inicio">{{ formatDate(assignment.started_on) }}</td>
                                        <td data-label="Estado">
                                            <span v-if="assignment.is_parallel" class="cdh-badge cdh-badge--info">
                                                En paralelo
                                            </span>
                                            <span v-else class="cdh-badge cdh-badge--success">Activa</span>
                                            <span
                                                v-if="assignment.is_parallel && assignment.parallel_reason"
                                                class="cdh-table__secondary"
                                            >
                                                {{ assignment.parallel_reason }}
                                            </span>
                                        </td>
                                        <td v-if="canManageRelationships" data-label="Acciones" class="cdh-table__actions">
                                            <button type="button" class="cdh-link" @click="openTransfer(assignment)">
                                                Transferir
                                            </button>
                                            <button type="button" class="cdh-link" @click="openClose(assignment)">
                                                Cerrar
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </article>

                <article class="cdh-card">
                    <h2 class="cdh-card__title">Historial de relaciones</h2>
                    <div class="cdh-card__body cdh-card__body--flush">
                        <AppEmptyState
                            v-if="historyCompanies.length === 0"
                            title="Sin historial"
                            description="Las relaciones cerradas se conservan aquí."
                            icon="bi-clock-history"
                        />
                        <div v-else class="cdh-table-wrap">
                            <table class="cdh-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Empresa</th>
                                        <th scope="col">Cargo</th>
                                        <th scope="col">Periodo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="assignment in historyCompanies" :key="assignment.id">
                                        <td data-label="Empresa">
                                            <span class="cdh-table__primary">
                                                {{ assignment.company?.display_name ?? '—' }}
                                            </span>
                                        </td>
                                        <td data-label="Cargo">{{ assignment.job_title ?? '—' }}</td>
                                        <td data-label="Periodo">
                                            {{ formatDate(assignment.started_on) }} —
                                            {{ formatDate(assignment.ended_on) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </article>
            </div>

            <!-- Afiliaciones -->
            <div
                v-show="tab === 'afiliaciones'"
                id="panel-afiliaciones"
                role="tabpanel"
                aria-labelledby="tab-afiliaciones"
            >
                <AppAlert
                    v-if="!canSeeAffiliations"
                    variant="info"
                    title="No tiene permiso para consultar las afiliaciones."
                />

                <template v-else>
                    <div class="d-flex justify-content-end mb-3">
                        <AppButton v-if="canManageAffiliations" icon="bi-plus-lg" @click="openAffiliation">
                            Registrar afiliación
                        </AppButton>
                    </div>

                    <article class="cdh-card mb-3">
                        <h2 class="cdh-card__title">Afiliaciones actuales</h2>
                        <div class="cdh-card__body cdh-card__body--flush">
                            <AppEmptyState
                                v-if="activeAffiliations.length === 0"
                                title="Sin afiliaciones registradas"
                                description="Registre la EPS, AFP, ARL y Caja de Compensación Familiar del cliente."
                                icon="bi-hospital"
                            />
                            <div v-else class="cdh-table-wrap">
                                <table class="cdh-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Tipo</th>
                                            <th scope="col">Entidad</th>
                                            <th scope="col">Desde</th>
                                            <th scope="col">Nivel de riesgo</th>
                                            <th scope="col"><span class="cdh-visually-hidden">Acciones</span></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="affiliation in activeAffiliations" :key="affiliation.id">
                                            <td data-label="Tipo">
                                                <span class="cdh-badge cdh-badge--info">
                                                    {{ affiliation.type_label }}
                                                </span>
                                            </td>
                                            <td data-label="Entidad">
                                                <span class="cdh-table__primary">
                                                    {{ affiliation.entity?.name ?? '—' }}
                                                </span>
                                            </td>
                                            <td data-label="Desde">{{ formatDate(affiliation.started_on) }}</td>
                                            <td data-label="Nivel de riesgo">
                                                <span v-if="affiliation.arl_risk_label" class="cdh-badge cdh-badge--warning">
                                                    {{ affiliation.arl_risk_label }}
                                                </span>
                                                <span v-else class="cdh-text-muted">—</span>
                                            </td>
                                            <td
                                                v-if="canManageAffiliations"
                                                data-label="Acciones"
                                                class="cdh-table__actions"
                                            >
                                                <button
                                                    type="button"
                                                    class="cdh-link"
                                                    @click="openCloseAffiliation(affiliation)"
                                                >
                                                    Cerrar
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </article>

                    <article class="cdh-card">
                        <h2 class="cdh-card__title">Historial de afiliaciones</h2>
                        <div class="cdh-card__body cdh-card__body--flush">
                            <AppEmptyState
                                v-if="historyAffiliations.length === 0"
                                title="Sin historial"
                                description="Las afiliaciones cerradas se conservan aquí."
                                icon="bi-clock-history"
                            />
                            <div v-else class="cdh-table-wrap">
                                <table class="cdh-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Tipo</th>
                                            <th scope="col">Entidad</th>
                                            <th scope="col">Periodo</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="affiliation in historyAffiliations" :key="affiliation.id">
                                            <td data-label="Tipo">{{ affiliation.type_label }}</td>
                                            <td data-label="Entidad">{{ affiliation.entity?.name ?? '—' }}</td>
                                            <td data-label="Periodo">
                                                {{ formatDate(affiliation.started_on) }} —
                                                {{ formatDate(affiliation.ended_on) }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </article>
                </template>
            </div>

            <!-- Historial -->
            <div v-show="tab === 'historial'" id="panel-historial" role="tabpanel" aria-labelledby="tab-historial">
                <article class="cdh-card">
                    <h2 class="cdh-card__title">Historial de la ficha</h2>
                    <div class="cdh-card__body">
                        <AppEmptyState
                            v-if="payload.history.length === 0"
                            title="Sin movimientos registrados"
                            description="Las altas, los cambios y los cierres quedan aquí."
                            icon="bi-clock-history"
                        />
                        <ul v-else class="cdh-timeline">
                            <li v-for="(entry, index) in payload.history" :key="index" class="cdh-timeline__item">
                                <span class="cdh-timeline__action">{{ humanAction(entry.action) }}</span>
                                <span v-if="entry.subject" class="cdh-badge cdh-badge--neutral">
                                    {{ entry.subject }}
                                </span>
                                <span class="cdh-timeline__meta">{{ formatMoment(entry.at) }}</span>
                            </li>
                        </ul>
                    </div>
                </article>
            </div>
        </template>

        <!-- Status confirmation -->
        <AppModal
            :open="statusOpen"
            title="Desactivar cliente"
            confirm-label="Desactivar"
            destructive
            :busy="busy"
            @confirm="applyStatus('inactive', statusChoice)"
            @cancel="statusOpen = false"
        >
            <p>
                El cliente dejará de aceptar inicio de sesión y no podrá vincularse a nuevas empresas. Su
                historial se conserva.
            </p>

            <AppAlert
                v-if="statusConflict"
                variant="warning"
                :title="statusConflict.message"
                class="mt-3"
            />

            <div v-if="statusConflict" class="mt-3">
                <label class="cdh-form-label" for="status-choice">¿Qué hacer con las relaciones abiertas?</label>
                <select
                    id="status-choice"
                    v-model="statusChoice"
                    class="form-control"
                    :disabled="busy"
                >
                    <option value="close">Cerrarlas en la fecha indicada</option>
                    <option value="block">Cancelar y cerrarlas manualmente</option>
                </select>

                <AppFormField
                    v-if="statusChoice === 'close'"
                    v-model="statusEffectiveDate"
                    label="Fecha de cierre"
                    name="status_effective_date"
                    type="date"
                    class="mt-2"
                />
            </div>
        </AppModal>

        <!-- Link a company -->
        <AppModal
            :open="linkOpen"
            title="Vincular empresa"
            confirm-label="Registrar"
            :busy="busy"
            @confirm="submitLink"
            @cancel="linkOpen = false"
        >
            <p class="cdh-text-sm cdh-text-muted">
                La relación se guarda como un periodo histórico. Si el cliente ya tiene una empresa abierta,
                deberá decidir qué hacer con ella.
            </p>

            <AppFormField
                v-model="companySearch"
                label="Buscar empresa"
                name="link_company_search"
                type="search"
                hint="Escriba para buscar entre todas las empresas."
            />

            <AppFormField
                v-model="linkForm.company_id"
                label="Empresa"
                name="link_company"
                required
                :options="companies.map((c) => ({ value: String(c.id), label: c.display_name }))"
                empty-label="Seleccione una empresa"
            />

            <AppFormField
                v-model="linkForm.started_on"
                label="Fecha de inicio"
                name="link_started_on"
                type="date"
                required
            />

            <AppFormField v-model="linkForm.job_title" label="Cargo" name="link_job_title" />

            <AppFormField
                v-if="needsReason"
                v-model="linkForm.parallel_reason"
                label="Justificación del paralelismo"
                name="link_parallel_reason"
                required
                hint="Queda registrada para que los controles de calidad distingan un solapamiento autorizado."
            />

            <AppAlert
                v-if="linkConflict"
                variant="warning"
                :title="linkConflict.message"
                class="mt-3"
            />

            <div v-if="linkConflict" class="cdh-stack-4 mt-3">
                <p class="cdh-text-sm mb-0">¿Qué desea hacer?</p>
                <div class="d-flex flex-column gap-2">
                    <button
                        v-for="option in linkConflict.options"
                        :key="option.value"
                        type="button"
                        class="cdh-warning cdh-warning--notice text-start"
                        :disabled="busy"
                        @click="applyResolution(option)"
                    >
                        <span class="cdh-warning__message">{{ option.label }}</span>
                        <span class="cdh-warning__suggestion">{{ option.description }}</span>
                    </button>
                </div>
            </div>
        </AppModal>

        <!-- Close relationship -->
        <AppModal
            :open="closeTarget !== null"
            title="Cerrar relación"
            confirm-label="Cerrar relación"
            :busy="busy"
            @confirm="confirmClose"
            @cancel="closeTarget = null"
        >
            <p>
                Se cerrará la relación con
                <strong>{{ closeTarget?.company?.display_name }}</strong
                >. El periodo quedará en el historial y no podrá volver a abrirse.
            </p>

            <AppFormField
                v-model="closeDate"
                label="Fecha de cierre"
                name="close_date"
                type="date"
                required
            />

            <AppFormField v-model="closeReason" label="Motivo" name="close_reason" />
        </AppModal>

        <!-- Transfer -->
        <AppModal
            :open="transferTarget !== null"
            title="Transferir cliente"
            confirm-label="Transferir"
            :busy="busy"
            @confirm="confirmTransfer"
            @cancel="transferTarget = null"
        >
            <p>
                Se cerrará la relación con <strong>{{ transferTarget?.company?.display_name }}</strong> en la
                fecha efectiva y se abrirá una nueva con la empresa de destino. El historial de la relación
                anterior no se modifica.
            </p>

            <AppFormField
                v-model="companySearch"
                label="Buscar empresa de destino"
                name="transfer_company_search"
                type="search"
                hint="Escriba para buscar entre todas las empresas."
            />

            <AppFormField
                v-model="transferForm.to_company_id"
                label="Empresa de destino"
                name="transfer_to"
                required
                :options="companies
                    .filter((c) => c.id !== transferTarget?.company_id)
                    .map((c) => ({ value: String(c.id), label: c.display_name }))"
                empty-label="Seleccione una empresa"
            />

            <AppFormField
                v-model="transferForm.effective_on"
                label="Fecha efectiva"
                name="transfer_effective_on"
                type="date"
                required
            />

            <AppFormField v-model="transferForm.job_title" label="Cargo en la nueva empresa" name="transfer_job" />
        </AppModal>

        <!-- Affiliation -->
        <AppModal
            :open="affiliationOpen"
            title="Registrar afiliación"
            confirm-label="Registrar"
            :busy="busy"
            @confirm="submitAffiliation"
            @cancel="affiliationOpen = false"
        >
            <AppFormField
                v-model="affiliationForm.type"
                label="Tipo"
                name="affiliation_type"
                required
                :options="AFFILIATION_TYPES"
            />

            <AppFormField
                v-model="affiliationForm.social_security_entity_id"
                label="Entidad"
                name="affiliation_entity"
                required
                :options="entityChoices"
                empty-label="Seleccione una entidad"
            />

            <AppFormField
                v-model="affiliationForm.started_on"
                label="Fecha de inicio"
                name="affiliation_started_on"
                type="date"
            />

            <AppFormField
                v-if="isArl"
                v-model="affiliationForm.arl_risk_class"
                label="Nivel de riesgo"
                name="affiliation_risk"
                :options="riskLevels"
                empty-label="Sin registrar"
                hint="Solo las afiliaciones de ARL tienen nivel de riesgo."
            />

            <AppFormField v-model="affiliationForm.notes" label="Notas" name="affiliation_notes" />

            <AppAlert
                v-if="affiliationConflict"
                variant="warning"
                :title="affiliationConflict.message"
                class="mt-3"
            />

            <div v-if="affiliationConflict" class="cdh-stack-4 mt-3">
                <p class="cdh-text-sm mb-0">¿Qué desea hacer?</p>
                <div class="d-flex flex-column gap-2">
                    <button
                        v-for="option in affiliationConflict.options"
                        :key="option.value"
                        type="button"
                        class="cdh-warning cdh-warning--notice text-start"
                        :disabled="busy"
                        @click="applyAffiliationChoice(option)"
                    >
                        <span class="cdh-warning__message">{{ option.label }}</span>
                        <span class="cdh-warning__suggestion">{{ option.description }}</span>
                    </button>
                </div>
            </div>
        </AppModal>

        <!-- Close affiliation -->
        <AppModal
            :open="closeAffiliationTarget !== null"
            title="Cerrar afiliación"
            confirm-label="Cerrar afiliación"
            :busy="busy"
            @confirm="confirmCloseAffiliation"
            @cancel="closeAffiliationTarget = null"
        >
            <p>
                Se cerrará la afiliación de {{ closeAffiliationTarget?.type_label }} a
                <strong>{{ closeAffiliationTarget?.entity?.name }}</strong
                >. El periodo quedará en el historial.
            </p>

            <AppFormField
                v-model="closeAffiliationDate"
                label="Fecha de cierre"
                name="affiliation_close_date"
                type="date"
                required
            />
        </AppModal>
    </section>
</template>