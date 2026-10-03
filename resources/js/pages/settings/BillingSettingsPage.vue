<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import AppModal from '@/components/ui/AppModal.vue';
import { useDebouncedRef } from '@/composables/useDebouncedRef';
import { mesActual, pesos, pesosDesdeTexto } from '@/composables/useFormatters';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';

import type { CutoffRuleSummary, RateSummary } from '@/types/api';

/**
 * Fechas de corte y valores.
 *
 * Both of these are configuration with an effective month, and the screen is built
 * around that rather than around a list of records to edit. A month already
 * generated has already used the configuration that applied to it, so the server
 * refuses to change that row: the way to change what a month bills is to add a rule
 * that starts from a later month.
 *
 * That refusal is shown as information here rather than hidden: an operator who
 * cannot save needs to know why, and the reason is the point of the module.
 */

const auth = useAuthStore();
const canManageCutoffs = computed(() => auth.can('cutoffs.manage'));
const canManageRates = computed(() => auth.can('rates.manage'));

const tab = ref<'cutoffs' | 'rates'>('cutoffs');
const loading = ref(true);
const error = ref<string | null>(null);
const notice = ref<string | null>(null);

// --- Cutoff rules ------------------------------------------------------------
const rules = ref<CutoffRuleSummary[]>([]);
const ruleSearch = ref('');
const settledRuleSearch = useDebouncedRef(ruleSearch, 300);

async function loadRules(): Promise<void> {
    try {
        rules.value = (await businessApi.billing.cutoffRules({ search: settledRuleSearch.value })).items;
    } catch (cause) {
        rules.value = [];
        error.value = cause instanceof ApiError ? cause.message : 'No fue posible cargar las fechas de corte.';
    }
}

const ruleOpen = ref(false);
const ruleBusy = ref(false);
const ruleError = ref<string | null>(null);
const ruleForm = ref({
    scope: 'general',
    effectiveMonth: mesActual(),
    cutoffDay: 10,
    monthOffset: 1,
    notes: '',
    clientId: null as number | null,
    clientSearch: '',
    companyId: null as number | null,
    companySearch: '',
});

const clientOptions = ref<Array<{ id: number; label: string }>>([]);
const companyOptions = ref<Array<{ id: number; label: string }>>([]);

async function searchClientOptions(): Promise<void> {
    try {
        const payload = await businessApi.clients.list({ search: ruleForm.value.clientSearch, per_page: 25 });
        clientOptions.value = payload.clients.map((client) => ({ id: client.id, label: client.full_name }));
    } catch {
        clientOptions.value = [];
    }
}

async function searchCompanyOptions(): Promise<void> {
    try {
        const payload = await businessApi.companies.list({ search: ruleForm.value.companySearch, per_page: 25 });
        companyOptions.value = payload.companies.map((company) => ({ id: company.id, label: company.display_name }));
    } catch {
        companyOptions.value = [];
    }
}

watch(() => ruleForm.value.clientSearch, () => void searchClientOptions());
watch(() => ruleForm.value.companySearch, () => void searchCompanyOptions());

const ruleCanSave = computed(() => {
    if (ruleForm.value.cutoffDay < 1 || ruleForm.value.cutoffDay > 31) {
        return false;
    }

    if (ruleForm.value.scope === 'client') {
        return ruleForm.value.clientId !== null;
    }

    if (ruleForm.value.scope === 'company') {
        return ruleForm.value.companyId !== null;
    }

    return true;
});

function openRuleDialog(): void {
    ruleError.value = null;
    ruleForm.value = {
        scope: 'general',
        effectiveMonth: mesActual(),
        cutoffDay: 10,
        monthOffset: 1,
        notes: '',
        clientId: null,
        clientSearch: '',
        companyId: null,
        companySearch: '',
    };
    ruleOpen.value = true;
}

async function saveRule(): Promise<void> {
    ruleBusy.value = true;
    ruleError.value = null;

    try {
        const result = await businessApi.billing.storeCutoffRule({
            scope: ruleForm.value.scope,
            client_id: ruleForm.value.scope === 'client' ? ruleForm.value.clientId : null,
            company_id: ruleForm.value.scope === 'company' ? ruleForm.value.companyId : null,
            effective_month: ruleForm.value.effectiveMonth,
            cutoff_day: ruleForm.value.cutoffDay,
            month_offset: ruleForm.value.monthOffset,
            notes: ruleForm.value.notes === '' ? null : ruleForm.value.notes,
        });

        ruleOpen.value = false;
        notice.value = result.message;
        await loadRules();
    } catch (cause) {
        ruleError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible guardar la fecha de corte.';
    } finally {
        ruleBusy.value = false;
    }
}

const ruleBeingEdited = ref<CutoffRuleSummary | null>(null);
const ruleEditDay = ref(10);
const ruleEditOffset = ref(1);
const ruleEditNotes = ref('');
const ruleEditBusy = ref(false);
const ruleEditError = ref<string | null>(null);

function askRuleEdit(rule: CutoffRuleSummary): void {
    ruleBeingEdited.value = rule;
    ruleEditDay.value = rule.cutoff_day;
    ruleEditOffset.value = rule.month_offset;
    ruleEditNotes.value = rule.notes ?? '';
    ruleEditError.value = null;
}

async function saveRuleEdit(): Promise<void> {
    if (ruleBeingEdited.value === null) {
        return;
    }

    ruleEditBusy.value = true;
    ruleEditError.value = null;

    try {
        const result = await businessApi.billing.updateCutoffRule(ruleBeingEdited.value.id, {
            cutoff_day: ruleEditDay.value,
            month_offset: ruleEditOffset.value,
            notes: ruleEditNotes.value === '' ? null : ruleEditNotes.value,
        });

        ruleBeingEdited.value = null;
        notice.value = result.message;
        await loadRules();
    } catch (cause) {
        // A rule already used is refused, and the message says which months it billed.
        ruleEditError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible actualizar la fecha de corte.';
    } finally {
        ruleEditBusy.value = false;
    }
}

// --- Rates -------------------------------------------------------------------
const rates = ref<RateSummary[]>([]);
const rateSearch = ref('');
const settledRateSearch = useDebouncedRef(rateSearch, 300);

async function loadRates(): Promise<void> {
    try {
        rates.value = (await businessApi.billing.rates({ search: settledRateSearch.value })).items;
    } catch (cause) {
        rates.value = [];
        error.value = cause instanceof ApiError ? cause.message : 'No fue posible cargar los valores.';
    }
}

const rateOpen = ref(false);
const rateBusy = ref(false);
const rateError = ref<string | null>(null);
const rateForm = ref({
    clientId: null as number | null,
    clientSearch: '',
    companyId: null as number | null,
    companySearch: '',
    effectiveMonth: mesActual(),
    amount: '',
    notes: '',
});

const rateClientOptions = ref<Array<{ id: number; label: string }>>([]);
const rateCompanyOptions = ref<Array<{ id: number; label: string }>>([]);

async function searchRateClients(): Promise<void> {
    try {
        const payload = await businessApi.clients.list({ search: rateForm.value.clientSearch, per_page: 25 });
        rateClientOptions.value = payload.clients.map((client) => ({ id: client.id, label: client.full_name }));
    } catch {
        rateClientOptions.value = [];
    }
}

async function searchRateCompanies(): Promise<void> {
    try {
        const payload = await businessApi.companies.list({
            search: rateForm.value.companySearch,
            per_page: 25,
        });
        rateCompanyOptions.value = payload.companies.map((company) => ({
            id: company.id,
            label: company.display_name,
        }));
    } catch {
        rateCompanyOptions.value = [];
    }
}

watch(() => rateForm.value.clientSearch, () => void searchRateClients());
watch(() => rateForm.value.companySearch, () => void searchRateCompanies());

const parsedRateAmount = computed(() => pesosDesdeTexto(rateForm.value.amount));
const rateAmountProblem = computed(() => {
    if (rateForm.value.amount.trim() === '') {
        return null;
    }

    if (parsedRateAmount.value === null) {
        return 'Escriba un número entero de pesos.';
    }

    if (parsedRateAmount.value <= 0) {
        return 'El valor debe ser mayor que cero.';
    }

    return null;
});

const rateCanSave = computed(
    () =>
        rateForm.value.clientId !== null &&
        rateForm.value.companyId !== null &&
        parsedRateAmount.value !== null &&
        parsedRateAmount.value > 0 &&
        rateAmountProblem.value === null,
);

function openRateDialog(): void {
    rateError.value = null;
    rateForm.value = {
        clientId: null,
        clientSearch: '',
        companyId: null,
        companySearch: '',
        effectiveMonth: mesActual(),
        amount: '',
        notes: '',
    };
    rateOpen.value = true;
}

async function saveRate(): Promise<void> {
    rateBusy.value = true;
    rateError.value = null;

    try {
        const result = await businessApi.billing.storeRate({
            client_id: rateForm.value.clientId as number,
            company_id: rateForm.value.companyId as number,
            effective_month: rateForm.value.effectiveMonth,
            amount_cop: parsedRateAmount.value as number,
            notes: rateForm.value.notes === '' ? null : rateForm.value.notes,
        });

        rateOpen.value = false;
        notice.value = result.message;
        await loadRates();
    } catch (cause) {
        rateError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible guardar el valor.';
    } finally {
        rateBusy.value = false;
    }
}

const rateBeingEdited = ref<RateSummary | null>(null);
const rateEditAmount = ref('');
const rateEditNotes = ref('');
const rateEditBusy = ref(false);
const rateEditError = ref<string | null>(null);

function askRateEdit(rate: RateSummary): void {
    rateBeingEdited.value = rate;
    rateEditAmount.value = String(rate.amount_cop);
    rateEditNotes.value = rate.notes ?? '';
    rateEditError.value = null;
}

async function saveRateEdit(): Promise<void> {
    if (rateBeingEdited.value === null) {
        return;
    }

    rateEditBusy.value = true;
    rateEditError.value = null;

    const amount = pesosDesdeTexto(rateEditAmount.value);

    try {
        // The amount is only sent when it actually changed, so a note can be corrected
        // on a rate that is already in use without the server refusing the whole save.
        const payload: { amount_cop?: number; notes?: string | null } = {
            notes: rateEditNotes.value === '' ? null : rateEditNotes.value,
        };

        if (amount !== null && amount > 0 && amount !== rateBeingEdited.value.amount_cop) {
            payload.amount_cop = amount;
        }

        const result = await businessApi.billing.updateRate(rateBeingEdited.value.id, payload);

        rateBeingEdited.value = null;
        notice.value = result.message;
        await loadRates();
    } catch (cause) {
        rateEditError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible actualizar el valor.';
    } finally {
        rateEditBusy.value = false;
    }
}

// --- Loading -----------------------------------------------------------------
async function loadAll(): Promise<void> {
    loading.value = true;
    error.value = null;

    await Promise.all([loadRules(), loadRates()]);

    loading.value = false;
}

onMounted(() => void loadAll());
watch(settledRuleSearch, () => void loadRules());
watch(settledRateSearch, () => void loadRates());
watch(tab, () => void loadAll());
</script>

<template>
    <section class="cdh-content">
        <header class="cdh-page-header">
            <div>
                <h1 class="cdh-page-header__title">Facturación</h1>
                <p class="cdh-page-header__subtitle">
                    Fechas de corte y valores con vigencia. La configuración que ya se usó no
                    se modifica: se agrega una nueva a partir de otro mes.
                </p>
            </div>

            <AppButton
                v-if="tab === 'cutoffs' && canManageCutoffs"
                icon="bi-plus-lg"
                @click="openRuleDialog"
            >
                Nueva fecha de corte
            </AppButton>

            <AppButton
                v-else-if="canManageRates"
                icon="bi-plus-lg"
                @click="openRateDialog"
            >
                Nuevo valor
            </AppButton>
        </header>

        <AppAlert v-if="error" variant="danger" :title="error" class="mb-4" />
        <AppAlert v-if="notice" variant="success" :title="notice" class="mb-4" />

        <div class="cdh-tabs mb-4" role="tablist">
            <button
                type="button"
                role="tab"
                class="cdh-tab"
                :class="{ 'cdh-tab--active': tab === 'cutoffs' }"
                :aria-selected="tab === 'cutoffs'"
                @click="tab = 'cutoffs'"
            >
                Fechas de corte
            </button>
            <button
                type="button"
                role="tab"
                class="cdh-tab"
                :class="{ 'cdh-tab--active': tab === 'rates' }"
                :aria-selected="tab === 'rates'"
                @click="tab = 'rates'"
            >
                Valores
            </button>
        </div>

        <AppLoading v-if="loading" label="Cargando configuración" />

        <template v-else-if="tab === 'cutoffs'">
            <div class="cdh-filters" role="search">
                <div class="cdh-filters__field cdh-filters__field--grow">
                    <label class="cdh-form-label" for="rules-search">Buscar</label>
                    <input
                        id="rules-search"
                        v-model="ruleSearch"
                        class="form-control form-control-sm"
                        type="search"
                        placeholder="Cliente o empresa"
                        autocomplete="off"
                    />
                </div>
            </div>

            <AppEmptyState
                v-if="rules.length === 0"
                title="Sin fechas de corte"
                description="Sin una regla general no se puede generar un mes. El sistema no la supone."
            />

            <div v-else class="cdh-table-wrap">
                <table class="cdh-table">
                    <caption class="cdh-visually-hidden">Fechas de corte configuradas</caption>
                    <thead>
                        <tr>
                            <th scope="col">Alcance</th>
                            <th scope="col" class="cdh-table__wide">Aplica a</th>
                            <th scope="col">Vigencia</th>
                            <th scope="col">Vencimiento</th>
                            <th scope="col" class="cdh-table__wide">Uso</th>
                            <th scope="col"><span class="cdh-visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="rule in rules" :key="rule.id">
                            <td data-label="Alcance">
                                <span class="cdh-table__primary">{{ rule.scope_label }}</span>
                            </td>
                            <td data-label="Aplica a" class="cdh-table__wide">
                                <span v-if="rule.scope === 'general'" class="cdh-table__secondary">
                                    Todos los clientes
                                </span>
                                <span v-else>{{ rule.client_name ?? rule.company_name }}</span>
                            </td>
                            <td data-label="Vigencia">{{ rule.effective_month_label }}</td>
                            <td data-label="Vencimiento">
                                Día {{ rule.cutoff_day }}
                                <span class="cdh-table__secondary">{{ rule.month_offset_label }}</span>
                            </td>
                            <td data-label="Uso">
                                <span
                                    v-if="rule.in_use"
                                    class="cdh-badge cdh-badge--neutral"
                                    title="Ya se usó para generar un mes"
                                >
                                    en uso
                                </span>
                                <span v-else class="cdh-badge cdh-badge--info">sin usar</span>
                            </td>
                            <td data-label="Acciones" class="cdh-table__actions">
                                <AppButton
                                    v-if="canManageCutoffs"
                                    variant="ghost"
                                    size="sm"
                                    @click="askRuleEdit(rule)"
                                >
                                    Editar
                                </AppButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <template v-else>
            <div class="cdh-filters" role="search">
                <div class="cdh-filters__field cdh-filters__field--grow">
                    <label class="cdh-form-label" for="rates-search">Buscar</label>
                    <input
                        id="rates-search"
                        v-model="rateSearch"
                        class="form-control form-control-sm"
                        type="search"
                        placeholder="Cliente o empresa"
                        autocomplete="off"
                    />
                </div>
            </div>

            <AppEmptyState
                v-if="rates.length === 0"
                title="Sin valores configurados"
                description="Sin un valor vigente no se puede generar el mes de un cliente."
            />

            <div v-else class="cdh-table-wrap">
                <table class="cdh-table">
                    <caption class="cdh-visually-hidden">Valores configurados</caption>
                    <thead>
                        <tr>
                            <th scope="col">Cliente</th>
                            <th scope="col" class="cdh-table__wide">Empresa</th>
                            <th scope="col">Vigencia</th>
                            <th scope="col">Valor</th>
                            <th scope="col" class="cdh-table__wide">Uso</th>
                            <th scope="col"><span class="cdh-visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="rate in rates" :key="rate.id">
                            <td data-label="Cliente">
                                <span class="cdh-table__primary">{{ rate.client_name }}</span>
                            </td>
                            <td data-label="Empresa" class="cdh-table__wide">{{ rate.company_name }}</td>
                            <td data-label="Vigencia">{{ rate.effective_month_label }}</td>
                            <td data-label="Valor" class="cdh-table__numeric">
                                {{ pesos(rate.amount_cop) }}
                            </td>
                            <td data-label="Uso">
                                <span v-if="rate.in_use" class="cdh-badge cdh-badge--neutral">en uso</span>
                                <span v-else class="cdh-badge cdh-badge--info">sin usar</span>
                            </td>
                            <td data-label="Acciones" class="cdh-table__actions">
                                <AppButton
                                    v-if="canManageRates"
                                    variant="ghost"
                                    size="sm"
                                    @click="askRateEdit(rate)"
                                >
                                    Editar
                                </AppButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- New cutoff rule -->
        <AppModal
            :open="ruleOpen"
            title="Nueva fecha de corte"
            confirm-label="Guardar"
            :busy="ruleBusy"
            :disabled="!ruleCanSave"
            @confirm="saveRule"
            @cancel="ruleOpen = false"
        >
            <div class="mb-3">
                <label class="cdh-form-label" for="rule-scope">Alcance</label>
                <select id="rule-scope" v-model="ruleForm.scope" class="form-control">
                    <option value="general">General</option>
                    <option value="company">Por empresa</option>
                    <option value="client">Por cliente</option>
                </select>
                <p class="cdh-form-hint">
                    Lo más específico gana: un cliente, luego una empresa, luego la general.
                </p>
            </div>

            <div v-if="ruleForm.scope === 'client'" class="mb-3">
                <label class="cdh-form-label" for="rule-client">Cliente</label>
                <input
                    id="rule-client-search"
                    v-model="ruleForm.clientSearch"
                    class="form-control form-control-sm mb-2"
                    type="search"
                    placeholder="Buscar cliente"
                />
                <select id="rule-client" v-model="ruleForm.clientId" class="form-control">
                    <option :value="null" disabled>Seleccione un cliente</option>
                    <option v-for="client in clientOptions" :key="client.id" :value="client.id">
                        {{ client.label }}
                    </option>
                </select>
            </div>

            <div v-if="ruleForm.scope === 'company'" class="mb-3">
                <label class="cdh-form-label" for="rule-company">Empresa</label>
                <input
                    id="rule-company-search"
                    v-model="ruleForm.companySearch"
                    class="form-control form-control-sm mb-2"
                    type="search"
                    placeholder="Buscar empresa"
                />
                <select id="rule-company" v-model="ruleForm.companyId" class="form-control">
                    <option :value="null" disabled>Seleccione una empresa</option>
                    <option v-for="company in companyOptions" :key="company.id" :value="company.id">
                        {{ company.label }}
                    </option>
                </select>
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="rule-month">Vigencia desde</label>
                <input
                    id="rule-month"
                    v-model="ruleForm.effectiveMonth"
                    class="form-control"
                    type="month"
                    required
                />
                <p class="cdh-form-hint">
                    Desde este mes en adelante, hasta que otra regla la reemplace.
                </p>
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="rule-day">Día de corte</label>
                <input
                    id="rule-day"
                    v-model.number="ruleForm.cutoffDay"
                    class="form-control"
                    type="number"
                    min="1"
                    max="31"
                    required
                />
                <p class="cdh-form-hint">
                    En un mes corto se usa el último día. Por ejemplo, el 31 de febrero es el
                    28.
                </p>
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="rule-offset">Mes de vencimiento</label>
                <select id="rule-offset" v-model.number="ruleForm.monthOffset" class="form-control">
                    <option :value="0">El mismo mes del periodo</option>
                    <option :value="1">El mes siguiente</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="rule-notes">Nota</label>
                <input
                    id="rule-notes"
                    v-model="ruleForm.notes"
                    class="form-control"
                    type="text"
                    placeholder="Acordado con la empresa"
                />
            </div>

            <AppAlert v-if="ruleError" variant="danger" :title="ruleError" />
        </AppModal>

        <!-- Editing a cutoff rule -->
        <AppModal
            :open="ruleBeingEdited !== null"
            title="Editar fecha de corte"
            confirm-label="Guardar"
            :busy="ruleEditBusy"
            @confirm="saveRuleEdit"
            @cancel="ruleBeingEdited = null"
        >
            <p v-if="ruleBeingEdited" class="cdh-form-hint">
                Vigencia desde {{ ruleBeingEdited.effective_month_label }}.
                <template v-if="ruleBeingEdited.in_use">
                    Esta regla ya generó un mes, así que el día y el mes de vencimiento no se
                    pueden cambiar. Las notas sí: describen la decisión, no la son.
                </template>
            </p>

            <div class="mb-3">
                <label class="cdh-form-label" for="rule-edit-day">Día de corte</label>
                <input
                    id="rule-edit-day"
                    v-model.number="ruleEditDay"
                    class="form-control"
                    type="number"
                    min="1"
                    max="31"
                    :disabled="ruleBeingEdited?.in_use ?? false"
                />
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="rule-edit-offset">Mes de vencimiento</label>
                <select
                    id="rule-edit-offset"
                    v-model.number="ruleEditOffset"
                    class="form-control"
                    :disabled="ruleBeingEdited?.in_use ?? false"
                >
                    <option :value="0">El mismo mes del periodo</option>
                    <option :value="1">El mes siguiente</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="rule-edit-notes">Nota</label>
                <input id="rule-edit-notes" v-model="ruleEditNotes" class="form-control" type="text" />
            </div>

            <AppAlert v-if="ruleEditError" variant="danger" :title="ruleEditError" />
        </AppModal>

        <!-- New rate -->
        <AppModal
            :open="rateOpen"
            title="Nuevo valor"
            confirm-label="Guardar"
            :busy="rateBusy"
            :disabled="!rateCanSave"
            @confirm="saveRate"
            @cancel="rateOpen = false"
        >
            <div class="mb-3">
                <label class="cdh-form-label" for="rate-client">Cliente</label>
                <input
                    id="rate-client-search"
                    v-model="rateForm.clientSearch"
                    class="form-control form-control-sm mb-2"
                    type="search"
                    placeholder="Buscar cliente"
                />
                <select id="rate-client" v-model="rateForm.clientId" class="form-control">
                    <option :value="null" disabled>Seleccione un cliente</option>
                    <option v-for="client in rateClientOptions" :key="client.id" :value="client.id">
                        {{ client.label }}
                    </option>
                </select>
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="rate-company">Empresa</label>
                <input
                    id="rate-company-search"
                    v-model="rateForm.companySearch"
                    class="form-control form-control-sm mb-2"
                    type="search"
                    placeholder="Buscar empresa"
                />
                <select id="rate-company" v-model="rateForm.companyId" class="form-control">
                    <option :value="null" disabled>Seleccione una empresa</option>
                    <option v-for="company in rateCompanyOptions" :key="company.id" :value="company.id">
                        {{ company.label }}
                    </option>
                </select>
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="rate-month">Vigencia desde</label>
                <input
                    id="rate-month"
                    v-model="rateForm.effectiveMonth"
                    class="form-control"
                    type="month"
                    required
                />
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="rate-amount">Valor mensual (pesos)</label>
                <input
                    id="rate-amount"
                    v-model="rateForm.amount"
                    class="form-control"
                    type="text"
                    inputmode="numeric"
                    placeholder="235000"
                />
                <p class="cdh-form-hint">
                    Un número entero, sin centavos. Es lo que se factura cada mes mientras
                    este valor esté vigente.
                </p>
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="rate-notes">Nota</label>
                <input id="rate-notes" v-model="rateForm.notes" class="form-control" type="text" />
            </div>

            <AppAlert v-if="rateAmountProblem" variant="danger" :title="rateAmountProblem" />
            <AppAlert v-if="rateError" variant="danger" :title="rateError" />
        </AppModal>

        <!-- Editing a rate -->
        <AppModal
            :open="rateBeingEdited !== null"
            title="Editar valor"
            confirm-label="Guardar"
            :busy="rateEditBusy"
            @confirm="saveRateEdit"
            @cancel="rateBeingEdited = null"
        >
            <p v-if="rateBeingEdited" class="cdh-form-hint">
                {{ rateBeingEdited.client_name }} en {{ rateBeingEdited.company_name }}, vigente
                desde {{ rateBeingEdited.effective_month_label }}.
                <template v-if="rateBeingEdited.in_use">
                    Este valor ya generó un mes, así que el monto no se puede cambiar: la nota
                    sí. Para cambiar lo que se factura, cree un valor nuevo con una vigencia
                    posterior.
                </template>
            </p>

            <div class="mb-3">
                <label class="cdh-form-label" for="rate-edit-amount">Valor mensual (pesos)</label>
                <input
                    id="rate-edit-amount"
                    v-model="rateEditAmount"
                    class="form-control"
                    type="text"
                    inputmode="numeric"
                    :disabled="rateBeingEdited?.in_use ?? false"
                />
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="rate-edit-notes">Nota</label>
                <input id="rate-edit-notes" v-model="rateEditNotes" class="form-control" type="text" />
            </div>

            <AppAlert v-if="rateEditError" variant="danger" :title="rateEditError" />
        </AppModal>
    </section>
</template>
