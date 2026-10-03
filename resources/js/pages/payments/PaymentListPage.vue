<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import AppModal from '@/components/ui/AppModal.vue';
import { useDebouncedRef } from '@/composables/useDebouncedRef';
import { fecha, hoy, pesos, pesosDesdeTexto } from '@/composables/useFormatters';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';

import type {
    AutoAllocationPlan,
    BillingVocabularyPayload,
    ObligationSummary,
    PaymentSummary,
    PossibleDuplicate,
} from '@/types/api';

/**
 * Los pagos.
 *
 * A payment is a fact: money arrived, on a day, in a way, from somebody. It is
 * recorded without being applied to anything, because what it should pay is a separate
 * decision that is often made later and sometimes by somebody else.
 *
 * That is why an unallocated payment is not an error here. It is money the business
 * holds and has not yet matched to a debt, which is a normal thing to have and is
 * counted as credit rather than as a mistake. The screen says so out loud instead of
 * colouring it as a fault.
 */

const auth = useAuthStore();
const canCreate = computed(() => auth.can('payments.create'));
const canAllocate = computed(() => auth.can('payments.allocate'));
const canVoid = computed(() => auth.can('payments.void'));

const vocabulary = ref<BillingVocabularyPayload | null>(null);

// --- Filters -----------------------------------------------------------------
const search = ref('');
const state = ref('');
const method = ref('');
const onlyUnreconciled = ref(false);
const page = ref(1);
const perPage = ref<number>(25);

const settledSearch = useDebouncedRef(search, 300);

const payments = ref<PaymentSummary[]>([]);
const total = ref(0);
const lastPage = ref(1);
const loading = ref(true);
const error = ref<string | null>(null);
const notice = ref<string | null>(null);

let requestId = 0;

async function load(): Promise<void> {
    const current = ++requestId;

    loading.value = true;
    error.value = null;

    try {
        const payload = await businessApi.payments.list({
            search: settledSearch.value,
            state: state.value,
            method: method.value,
            requires_reconciliation: onlyUnreconciled.value,
            page: page.value,
            per_page: perPage.value,
        });

        if (current !== requestId) {
            return;
        }

        payments.value = payload.items;
        total.value = payload.pagination.total;
        lastPage.value = payload.pagination.last_page;
    } catch (cause) {
        if (current !== requestId) {
            return;
        }

        payments.value = [];
        error.value = cause instanceof ApiError ? cause.message : 'No fue posible cargar los pagos.';
    } finally {
        if (current === requestId) {
            loading.value = false;
        }
    }
}

async function loadVocabulary(): Promise<void> {
    try {
        vocabulary.value = await businessApi.receivables.vocabulary();
    } catch {
        // The filters are a convenience. An empty select is a lesser harm than a
        // screen that refuses to load because a label list was unavailable.
        vocabulary.value = null;
    }
}

onMounted(() => {
    void load();
    void loadVocabulary();
});

watch([settledSearch, state, method, onlyUnreconciled, perPage], () => {
    page.value = 1;
    void load();
});

watch(page, () => void load());

// --- Registering a payment ---------------------------------------------------
const registerOpen = ref(false);
const registerBusy = ref(false);
const registerError = ref<string | null>(null);

const form = ref({
    clientId: null as number | null,
    clientSearch: '',
    amount: '',
    receivedOn: hoy(),
    method: 'cash',
    reference: '',
    notes: '',
});

const clientOptions = ref<Array<{ id: number; label: string }>>([]);
const clientSearchError = ref<string | null>(null);

const parsedAmount = computed(() => pesosDesdeTexto(form.value.amount));
const amountProblem = computed(() => {
    if (form.value.amount.trim() === '') {
        return null;
    }

    if (parsedAmount.value === null) {
        return 'Escriba un número entero de pesos. Este sistema no maneja centavos.';
    }

    if (parsedAmount.value <= 0) {
        return 'El valor debe ser mayor que cero.';
    }

    return null;
});

const canRegister = computed(
    () =>
        form.value.clientId !== null &&
        parsedAmount.value !== null &&
        parsedAmount.value > 0 &&
        form.value.receivedOn !== '' &&
        form.value.method !== '',
);

function openRegister(): void {
    registerError.value = null;
    duplicates.value = [];
    form.value = {
        clientId: null,
        clientSearch: '',
        amount: '',
        receivedOn: hoy(),
        method: 'cash',
        reference: '',
        notes: '',
    };
    registerOpen.value = true;
}

/**
 * Fill the client picker.
 *
 * A failure here leaves the list empty rather than showing a stale one, because a
 * picker offering the wrong client is worse than a picker offering none: the operator
 * would record a payment against somebody they did not mean.
 */
async function searchClients(): Promise<void> {
    try {
        const payload = await businessApi.clients.list({ search: form.value.clientSearch, per_page: 25 });

        clientOptions.value = payload.clients.map((client) => ({ id: client.id, label: client.full_name }));
        clientSearchError.value = null;
    } catch {
        clientOptions.value = [];
        clientSearchError.value = 'No fue posible buscar clientes. Escriba el nombre completo e intente otra vez.';
    }
}

watch(() => form.value.clientSearch, () => void searchClients());

const duplicates = ref<PossibleDuplicate[]>([]);

async function registerPayment(confirmDuplicate = false): Promise<void> {
    if (!canRegister.value) {
        registerError.value = 'Complete el cliente, el valor y la fecha.';
        return;
    }

    registerBusy.value = true;
    registerError.value = null;

    try {
        const result = await businessApi.payments.register({
            client_id: form.value.clientId as number,
            amount_cop: parsedAmount.value as number,
            received_on: form.value.receivedOn,
            method: form.value.method,
            reference: form.value.reference === '' ? null : form.value.reference,
            notes: form.value.notes === '' ? null : form.value.notes,
            confirm_duplicate: confirmDuplicate,
        });

        registerOpen.value = false;
        notice.value = result.message;
        await load();
    } catch (cause) {
        if (cause instanceof ApiError && cause.code === 'possible_duplicate') {
            // Real money may be at stake, so this is a question and not a refusal. The
            // duplicates come out of the error body: the server sends them with the
            // warning so the operator can compare without a second request.
            const body = cause.payload as { possible_duplicates?: PossibleDuplicate[] } | null;

            duplicates.value = body?.possible_duplicates ?? [];
            return;
        }

        registerError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible registrar el pago.';
    } finally {
        registerBusy.value = false;
    }
}

// --- Applying a payment ------------------------------------------------------
const applyTarget = ref<PaymentSummary | null>(null);
const applyObligations = ref<ObligationSummary[]>([]);
const applyObligationId = ref<number | null>(null);
const applyAmount = ref('');
const applyBusy = ref(false);
const applyError = ref<string | null>(null);
const applyPlan = ref<AutoAllocationPlan | null>(null);
const planBusy = ref(false);

const parsedApplyAmount = computed(() => {
    const parsed = pesosDesdeTexto(applyAmount.value);

    if (parsed === null) {
        return null;
    }

    // Capped rather than refused: an operator typing the client's whole balance into
    // a payment that covers part of it means "as much as this payment reaches".
    return Math.min(parsed, applyTarget.value?.unallocated_amount_cop ?? 0);
});

const applyProblem = computed(() => {
    if (applyAmount.value.trim() === '') {
        return null;
    }

    const parsed = pesosDesdeTexto(applyAmount.value);

    if (parsed === null) {
        return 'Escriba un número entero de pesos.';
    }

    if (parsed <= 0) {
        return 'El valor debe ser mayor que cero.';
    }

    if (parsed > (applyTarget.value?.unallocated_amount_cop ?? 0)) {
        return `El pago sólo tiene ${pesos(applyTarget.value?.unallocated_amount_cop ?? 0)} sin aplicar.`;
    }

    return null;
});

async function openApply(payment: PaymentSummary): Promise<void> {
    applyTarget.value = payment;
    applyObligationId.value = null;
    applyAmount.value = String(payment.unallocated_amount_cop);
    applyError.value = null;
    applyPlan.value = null;
    await loadOpenDebts(payment.client_id);
}

/**
 * What this client still owes, oldest first.
 *
 * Read from the portfolio rather than kept here: a second copy of the debt would be
 * a second thing to get wrong.
 */
async function loadOpenDebts(clientId: number): Promise<void> {
    try {
        const account = await businessApi.receivables.clientAccount(clientId);

        // Only what still owes something. Applying to a settled obligation would be
        // refused by the server, and offering it here would just be a way to find out.
        applyObligations.value = account.obligations.filter(
            (obligation) => obligation.balance_cop > 0,
        );

        // Oldest first, which is the order the automatic application uses. An
        // operator overriding it is choosing to deviate deliberately.
        applyObligations.value.sort((a, b) => (a.due_on ?? '').localeCompare(b.due_on ?? ''));

        if (applyObligations.value.length > 0) {
            applyObligationId.value = applyObligations.value[0].id;
        }
    } catch (cause) {
        applyObligations.value = [];
        applyError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible cargar las obligaciones.';
    }
}

async function submitAllocation(): Promise<void> {
    if (applyTarget.value === null || applyObligationId.value === null || parsedApplyAmount.value === null) {
        applyError.value = 'Elija la obligación y el valor a aplicar.';
        return;
    }

    applyBusy.value = true;
    applyError.value = null;

    try {
        const result = await businessApi.payments.allocate(applyTarget.value.id, {
            obligation_id: applyObligationId.value,
            amount_cop: parsedApplyAmount.value,
        });

        applyTarget.value = null;
        notice.value = result.message;
        await load();
    } catch (cause) {
        applyError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible aplicar el pago.';
    } finally {
        applyBusy.value = false;
    }
}

async function previewPlan(): Promise<void> {
    if (applyTarget.value === null) {
        return;
    }

    planBusy.value = true;
    applyError.value = null;

    try {
        applyPlan.value = (await businessApi.payments.previewAutoAllocation(applyTarget.value.id)).plan;
    } catch (cause) {
        applyError.value = cause instanceof ApiError ? cause.message : 'No fue posible calcular.';
    } finally {
        planBusy.value = false;
    }
}

async function applyOldestFirst(): Promise<void> {
    if (applyTarget.value === null) {
        return;
    }

    applyBusy.value = true;
    applyError.value = null;

    try {
        const result = await businessApi.payments.autoAllocate(applyTarget.value.id);

        applyTarget.value = null;
        applyPlan.value = null;
        notice.value = result.message;
        await load();
    } catch (cause) {
        applyError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible aplicar el pago.';
    } finally {
        applyBusy.value = false;
    }
}

// --- Voiding -----------------------------------------------------------------
const voidTarget = ref<PaymentSummary | null>(null);
const voidReason = ref('');
const voidBusy = ref(false);
const voidError = ref<string | null>(null);

function askVoid(payment: PaymentSummary): void {
    voidTarget.value = payment;
    voidReason.value = '';
    voidError.value = null;
}

async function voidPayment(): Promise<void> {
    if (voidTarget.value === null) {
        return;
    }

    voidBusy.value = true;
    voidError.value = null;

    try {
        const result = await businessApi.payments.void(voidTarget.value.id, voidReason.value);

        voidTarget.value = null;
        notice.value = result.message;
        await load();
    } catch (cause) {
        voidError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible anular el pago.';
    } finally {
        voidBusy.value = false;
    }
}

const stateBadge: Record<string, string> = {
    unallocated: 'cdh-badge--warning',
    partially_allocated: 'cdh-badge--info',
    fully_allocated: 'cdh-badge--success',
    voided: 'cdh-badge--neutral',
};

const rangeLabel = computed(() => {
    if (total.value === 0) {
        return 'Sin resultados';
    }

    const from = (page.value - 1) * perPage.value + 1;
    const to = Math.min(page.value * perPage.value, total.value);

    return `${from}–${to} de ${total.value}`;
});
</script>

<template>
    <section class="cdh-content">
        <header class="cdh-page-header">
            <div>
                <h1 class="cdh-page-header__title">Pagos</h1>
                <p class="cdh-page-header__subtitle">
                    {{ rangeLabel }}
                </p>
            </div>

            <AppButton v-if="canCreate" icon="bi-plus-lg" @click="openRegister">
                Registrar pago
            </AppButton>
        </header>

        <AppAlert v-if="error" variant="danger" :title="error" class="mb-4" />
        <AppAlert v-if="notice" variant="success" :title="notice" class="mb-4" />

        <div class="cdh-filters" role="search">
            <div class="cdh-filters__field cdh-filters__field--grow">
                <label class="cdh-form-label" for="payments-search">Buscar</label>
                <input
                    id="payments-search"
                    v-model="search"
                    class="form-control form-control-sm"
                    type="search"
                    placeholder="Cliente o referencia"
                    autocomplete="off"
                />
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="payments-state">Estado</label>
                <select id="payments-state" v-model="state" class="form-control form-control-sm">
                    <option value="">Todos</option>
                    <option value="unallocated">Sin aplicar</option>
                    <option value="partially_allocated">Aplicado parcialmente</option>
                    <option value="fully_allocated">Aplicado por completo</option>
                    <option value="voided">Anulados</option>
                </select>
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="payments-method">Medio</label>
                <select id="payments-method" v-model="method" class="form-control form-control-sm">
                    <option value="">Todos</option>
                    <option value="cash">Efectivo</option>
                    <option value="bank_transfer">Transferencia bancaria</option>
                    <option value="deposit">Consignación</option>
                    <option value="other">Otro</option>
                </select>
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="payments-per-page">Por página</label>
                <select
                    id="payments-per-page"
                    class="form-control form-control-sm"
                    :value="perPage"
                    @change="perPage = Number(($event.target as HTMLSelectElement).value)"
                >
                    <option :value="25">25</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                </select>
            </div>

            <div class="cdh-filters__field">
                <label class="cdh-form-label" for="payments-unreconciled">&nbsp;</label>
                <div class="form-check">
                    <input
                        id="payments-unreconciled"
                        v-model="onlyUnreconciled"
                        class="form-check-input"
                        type="checkbox"
                    />
                    <label class="form-check-label" for="payments-unreconciled">
                        Sólo sin conciliar
                    </label>
                </div>
            </div>
        </div>

        <AppLoading v-if="loading && payments.length === 0" label="Cargando pagos" />

        <AppEmptyState
            v-else-if="!error && payments.length === 0"
            title="Sin pagos registrados"
            description="Registre el primer pago recibido de un cliente."
        />

        <template v-else>
            <div class="cdh-table-wrap">
                <table class="cdh-table">
                    <caption class="cdh-visually-hidden">Pagos registrados</caption>
                    <thead>
                        <tr>
                            <th scope="col">Cliente</th>
                            <th scope="col">Fecha</th>
                            <th scope="col">Medio</th>
                            <th scope="col">Referencia</th>
                            <th scope="col">Valor</th>
                            <th scope="col">Aplicado</th>
                            <th scope="col">Sin aplicar</th>
                            <th scope="col">Estado</th>
                            <th scope="col"><span class="cdh-visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="payment in payments" :key="payment.id">
                            <td data-label="Cliente">
                                <span class="cdh-table__primary">{{ payment.client_name }}</span>
                            </td>
                            <td data-label="Fecha">{{ fecha(payment.received_on) }}</td>
                            <td data-label="Medio">{{ payment.method_label }}</td>
                            <td data-label="Referencia">
                                <span v-if="payment.reference">{{ payment.reference }}</span>
                                <span v-else class="cdh-table__secondary">—</span>
                            </td>
                            <td data-label="Valor" class="cdh-table__numeric">
                                {{ pesos(payment.amount_cop) }}
                            </td>
                            <td data-label="Aplicado" class="cdh-table__numeric">
                                {{ pesos(payment.allocated_amount_cop) }}
                            </td>
                            <td
                                data-label="Sin aplicar"
                                class="cdh-table__numeric"
                                :class="{ 'cdh-warning': payment.unallocated_amount_cop > 0 }"
                            >
                                {{ pesos(payment.unallocated_amount_cop) }}
                            </td>
                            <td data-label="Estado">
                                <span
                                    class="cdh-badge"
                                    :class="stateBadge[payment.reconciliation_state] ?? 'cdh-badge--neutral'"
                                >
                                    {{ payment.reconciliation_state_label }}
                                </span>
                            </td>
                            <td data-label="Acciones" class="cdh-table__actions">
                                <div class="cdh-stack-2">
                                    <AppButton
                                        v-if="canAllocate && !payment.is_voided && payment.unallocated_amount_cop > 0"
                                        variant="secondary"
                                        size="sm"
                                        @click="openApply(payment)"
                                    >
                                        Aplicar
                                    </AppButton>

                                    <AppButton
                                        v-if="canVoid && !payment.is_voided"
                                        variant="ghost"
                                        size="sm"
                                        @click="askVoid(payment)"
                                    >
                                        Anular
                                    </AppButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="lastPage > 1" class="cdh-pagination">
                <AppButton variant="secondary" :disabled="page <= 1" @click="page -= 1">
                    Anterior
                </AppButton>

                <span class="cdh-pagination__label">{{ rangeLabel }}</span>

                <AppButton variant="secondary" :disabled="page >= lastPage" @click="page += 1">
                    Siguiente
                </AppButton>
            </nav>
        </template>

        <!-- Registering -->
        <AppModal
            :open="registerOpen"
            title="Registrar pago"
            confirm-label="Registrar"
            :busy="registerBusy"
            :disabled="!canRegister"
            @confirm="registerPayment(false)"
            @cancel="registerOpen = false"
        >
            <div class="mb-3">
                <label class="cdh-form-label" for="payment-client">Cliente</label>
                <input
                    id="payment-client-search"
                    v-model="form.clientSearch"
                    class="form-control form-control-sm mb-2"
                    type="search"
                    placeholder="Buscar cliente"
                    autocomplete="off"
                />
                <p v-if="clientSearchError" class="cdh-form-error">{{ clientSearchError }}</p>

                <select id="payment-client" v-model="form.clientId" class="form-control" required>
                    <option :value="null" disabled>Seleccione un cliente</option>
                    <option v-for="client in clientOptions" :key="client.id" :value="client.id">
                        {{ client.label }}
                    </option>
                </select>
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="payment-amount">Valor (pesos)</label>
                <input
                    id="payment-amount"
                    v-model="form.amount"
                    class="form-control"
                    type="text"
                    inputmode="numeric"
                    placeholder="235000"
                    required
                />
                <p class="cdh-form-hint">
                    Un número entero, sin centavos. Se puede aplicar a otro mes más adelante.
                </p>
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="payment-date">Fecha de recepción</label>
                <input
                    id="payment-date"
                    v-model="form.receivedOn"
                    class="form-control"
                    type="date"
                    required
                />
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="payment-method-field">Medio</label>
                <select id="payment-method-field" v-model="form.method" class="form-control">
                    <option v-for="option in vocabulary?.payment_methods ?? []" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="payment-reference">Referencia</label>
                <input
                    id="payment-reference"
                    v-model="form.reference"
                    class="form-control"
                    type="text"
                    placeholder="Recibo, comprobante o nota de crédito"
                />
            </div>

            <AppAlert v-if="amountProblem" variant="danger" :title="amountProblem" />

            <!-- A warning, never a refusal: institutions reuse references. -->
            <AppAlert
                v-if="duplicates.length > 0"
                variant="warning"
                title="Puede ser un pago repetido"
                description="Hay un pago con el mismo cliente, valor, fecha y referencia. Revise antes de continuar."
            >
                <ul class="mb-2">
                    <li v-for="duplicate in duplicates" :key="duplicate.id">
                        #{{ duplicate.id }} — {{ pesos(duplicate.amount_cop) }} del
                        {{ fecha(duplicate.received_on) }}
                    </li>
                </ul>

                <div class="cdh-stack-2">
                    <AppButton variant="secondary" @click="duplicates = []">Revisar los datos</AppButton>
                    <AppButton :busy="registerBusy" @click="registerPayment(true)">
                        Es otro pago, registrar
                    </AppButton>
                </div>
            </AppAlert>

            <AppAlert v-if="registerError" variant="danger" :title="registerError" />
        </AppModal>

        <!-- Applying -->
        <AppModal
            :open="applyTarget !== null"
            title="Aplicar pago"
            confirm-label="Aplicar"
            :busy="applyBusy"
            :disabled="applyObligationId === null || parsedApplyAmount === null || applyProblem !== null"
            wide
            @confirm="submitAllocation"
            @cancel="applyTarget = null"
        >
            <p class="cdh-form-hint">
                Este pago tiene {{ pesos(applyTarget?.unallocated_amount_cop ?? 0) }} sin aplicar.
                Aplicarlo no lo borra: queda registrado contra la obligación elegida.
            </p>

            <div class="mb-3">
                <label class="cdh-form-label" for="apply-oldest-first">Aplicación sugerida</label>
                <p class="cdh-form-hint">
                    Del periodo más antiguo al más reciente. Se puede ver antes de aplicar.
                </p>

                <AppButton v-if="applyPlan === null" variant="secondary" :busy="planBusy" @click="previewPlan">
                    Ver plan
                </AppButton>

                <div v-else class="cdh-table-wrap">
                    <table class="cdh-table">
                        <caption class="cdh-visually-hidden">Plan de aplicación</caption>
                        <thead>
                            <tr>
                                <th scope="col">Periodo</th>
                                <th scope="col">Cliente</th>
                                <th scope="col">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in applyPlan.allocations" :key="line.obligation_id">
                                <td data-label="Periodo">{{ line.period_label }}</td>
                                <td data-label="Cliente">
                                    {{ applyTarget?.client_name }}
                                </td>
                                <td data-label="Valor" class="cdh-table__numeric">
                                    {{ pesos(line.would_apply_cop) }}
                                </td>
                            </tr>
                            <tr v-if="applyPlan.allocations.length === 0">
                                <td colspan="3" class="cdh-table__secondary">
                                    Este cliente no tiene saldo pendiente.
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p class="cdh-form-hint mt-2">
                        Aplicaría {{ pesos(applyPlan.would_apply_cop) }} en
                        {{ applyPlan.would_apply_count }}
                        {{ applyPlan.would_apply_count === 1 ? 'obligación' : 'obligaciones' }} y
                        quedarían {{ pesos(applyPlan.would_remain_unallocated_cop) }} sin aplicar.
                    </p>

                    <AppButton :busy="applyBusy" @click="applyOldestFirst">
                        Aplicar este plan
                    </AppButton>
                </div>
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="apply-obligation">Obligación</label>
                <select
                    id="apply-obligation"
                    v-model="applyObligationId"
                    class="form-control"
                    required
                >
                    <option :value="null" disabled>Seleccione la obligación</option>
                    <option v-for="row in applyObligations" :key="row.id" :value="row.id">
                        {{ row.period_label }} — {{ row.company_name }} — vence
                        {{ fecha(row.due_on) }} — {{ pesos(row.balance_cop) }}
                    </option>
                </select>
                <p class="cdh-form-hint">
                    Sólo las obligaciones de este cliente que todavía deben algo.
                </p>
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="apply-amount">Valor a aplicar (pesos)</label>
                <input
                    id="apply-amount"
                    v-model="applyAmount"
                    class="form-control"
                    type="text"
                    inputmode="numeric"
                />
            </div>

            <AppAlert v-if="applyProblem" variant="danger" :title="applyProblem" />
            <AppAlert v-if="applyError" variant="danger" :title="applyError" />
        </AppModal>

        <!-- Voiding: the payment stays, it stops counting -->
        <AppModal
            :open="voidTarget !== null"
            title="Anular pago"
            confirm-label="Anular pago"
            :busy="voidBusy"
            :disabled="voidReason.trim() === ''"
            destructive
            @confirm="voidPayment"
            @cancel="voidTarget = null"
        >
            <p>
                El pago de <strong>{{ pesos(voidTarget?.amount_cop ?? 0) }}</strong> dejará de
                contar en todos los saldos. El registro se conserva, junto con sus
                aplicaciones: queda visible que se anuló y por qué.
            </p>

            <div class="mb-3">
                <label class="cdh-form-label" for="void-reason">Motivo</label>
                <textarea
                    id="void-reason"
                    v-model="voidReason"
                    class="form-control"
                    rows="3"
                    required
                ></textarea>
                <p class="cdh-form-hint">Queda en la auditoría. Sin motivo no se anula.</p>
            </div>

            <AppAlert v-if="voidError" variant="danger" :title="voidError" />
        </AppModal>
    </section>
</template>
