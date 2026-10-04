<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import AppModal from '@/components/ui/AppModal.vue';
import { useDebouncedRef } from '@/composables/useDebouncedRef';
import { fecha, fechaHora, hoy, pesos, pesosDesdeTexto } from '@/composables/useFormatters';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';
import { reasonIsLongEnough, REASON_MIN_LENGTH_HINT } from '@/validation/reasons';

import type {
    AllocatableDebt,
    AutoAllocationPlan,
    PaymentAllocationSummary,
    PaymentSummary,
    PaymentVocabularyPayload,
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

const vocabulary = ref<PaymentVocabularyPayload | null>(null);

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
        // The payments domain's own vocabulary: the receivables one made this screen
        // depend on a permission it has no reason to need.
        vocabulary.value = await businessApi.payments.vocabulary();
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
/*
 * §17. Allocation history and reversal.
 *
 * The endpoint to undo an allocation was published while nothing in the interface could
 * reach it, so correcting a misapplied payment meant writing a request by hand — and a
 * correction an operator cannot perform is a correction that gets worked around in the
 * ledger instead of in it.
 *
 * The history is deliberately not filtered. A reversed allocation stays in the list, marked
 * as reversed, with the reason and the moment it happened: hiding it would make the record
 * look like the money was never applied, which is a different history and a wrong one.
 */
const historyTarget = ref<PaymentSummary | null>(null);
const historyBusy = ref(false);
const historyError = ref<string | null>(null);

const historyAllocations = computed<PaymentAllocationSummary[]>(
    () => historyTarget.value?.allocations ?? [],
);

/** What reversing gives back, stated before the operator commits to it. */
const reversalTarget = ref<PaymentAllocationSummary | null>(null);
const reversalReason = ref('');
const reversalBusy = ref(false);
const reversalError = ref<string | null>(null);

// §4 of R4. This read `reversalReason.value.trim().length < 10` — a literal, in a file that
// already imports the shared predicate for the void flow two hundred lines up. Both values
// happen to be 10, so it was functionally correct and structurally a second implementation of
// the same rule: the next person to change `REASON_MIN_LENGTH` would have changed the void
// dialog and left this one behind, and the reversal would have started accepting a reason the
// server refuses. The predicate and the sentence both come from the module now, as the other
// three dialogs do.
const reversalProblem = computed(() =>
    reasonIsLongEnough(reversalReason.value) ? null : REASON_MIN_LENGTH_HINT,
);

async function openHistory(payment: PaymentSummary): Promise<void> {
    historyTarget.value = payment;
    historyError.value = null;
    historyBusy.value = true;

    try {
        // Re-read rather than trust the row: the list does not carry allocations, and a
        // stale history is worse than none for a screen whose whole job is the record.
        const payload = await businessApi.payments.show(payment.id);

        historyTarget.value = payload.payment;
    } catch (caught) {
        historyError.value =
            caught instanceof ApiError ? caught.message : 'No se pudo cargar el historial.';
    } finally {
        historyBusy.value = false;
    }
}

function closeHistory(): void {
    historyTarget.value = null;
}

function askReverse(allocation: PaymentAllocationSummary): void {
    reversalTarget.value = allocation;
    reversalReason.value = '';
    reversalError.value = null;
}

function closeReverse(): void {
    reversalTarget.value = null;
    reversalReason.value = '';
    reversalError.value = null;
}

async function confirmReverse(): Promise<void> {
    if (reversalTarget.value === null || reversalProblem.value !== null) {
        return;
    }

    const paymentId = historyTarget.value?.id ?? null;

    if (paymentId === null) {
        return;
    }

    reversalBusy.value = true;
    reversalError.value = null;

    try {
        const payload = await businessApi.payments.reverseAllocation(
            reversalTarget.value.id,
            reversalReason.value.trim(),
        );

        notice.value = payload.message;

        // The server returns the re-read payment, so the row reflects the reversal without
        // a second request — and the reversed allocation is still in `allocations`.
        historyTarget.value = payload.payment;
        reversalTarget.value = null;
        reversalReason.value = '';

        await load();
    } catch (caught) {
        reversalError.value =
            caught instanceof ApiError ? caught.message : 'No se pudo revertir la aplicación.';
    } finally {
        reversalBusy.value = false;
    }
}

const applyTarget = ref<PaymentSummary | null>(null);
const applyObligationId = ref<number | null>(null);
const applyAmount = ref('');
const applyBusy = ref(false);
const applyError = ref<string | null>(null);
const applyPlan = ref<AutoAllocationPlan | null>(null);
const planBusy = ref(false);

const applyObligations = ref<AllocatableDebt[]>([]);

/**
 * What the operator typed, validated.
 *
 * **Not capped.** It used to be silently clamped to the payment's unapplied balance, which
 * meant the field showed one number and the request sent another, and the cap was
 * unreachable behind `applyProblem`. The value is now either acceptable or it is reported.
 */
const parsedApplyAmount = computed(() => {
    const parsed = pesosDesdeTexto(applyAmount.value);

    return parsed === null || parsed <= 0 ? null : parsed;
});

/** What the operator typed against what is actually available. */
const availableOnPayment = computed(() => applyTarget.value?.unallocated_amount_cop ?? 0);

/** The selected debt, or null when none is chosen. */
const selectedDebt = computed(
    () => applyObligations.value.find((debt) => debt.obligation_id === applyObligationId.value) ?? null,
);

/**
 * The amount to suggest: the smaller of what the payment has left and what the debt needs.
 *
 * §16. The modal used to default to the payment's whole unallocated balance, so a 300000
 * payment opened against a 200000 debt arrived pre-filled with 300000 — a figure the server
 * correctly refuses. The operator's first action would have been to delete most of it.
 *
 * Both limits are real and different in kind: the payment cannot give what it does not have,
 * and the debt cannot absorb more than it owes. The remainder stays as credit either way.
 */
const suggestedApplyAmount = computed(() => {
    if (selectedDebt.value === null) {
        return availableOnPayment.value;
    }

    return Math.min(availableOnPayment.value, selectedDebt.value.balance_cop);
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

    if (parsed > availableOnPayment.value) {
        return `El pago sólo tiene ${pesos(availableOnPayment.value)} sin aplicar.`;
    }

    if (selectedDebt.value !== null && parsed > selectedDebt.value.balance_cop) {
        // Says which limit was reached. Without it an operator can only tell that "algo"
        // is too much, and the two limits need different corrections.
        return `Esa obligación sólo necesita ${pesos(selectedDebt.value.balance_cop)}. `
            .concat('Aplique el resto a otra deuda o déjelo como anticipo.');
    }

    return null;
});

async function openApply(payment: PaymentSummary): Promise<void> {
    applyTarget.value = payment;
    applyObligationId.value = null;
    applyAmount.value = '';
    applyError.value = null;
    applyPlan.value = null;

    await loadOpenDebts(payment.client_id);

    // Suggested once the debts are known, since the suggestion depends on the selection.
    applyAmount.value = String(suggestedApplyAmount.value);
}

/**
 * What this client still owes, oldest first.
 *
 * Read from the **payments** domain's own endpoint rather than from the client statement.
 * §42: this loaded `clientAccount` through `receivables.view`, so `payments.allocate`
 * silently depended on an unrelated read permission and a collections role without it could
 * not use the button it had been granted. The endpoint publishes only what choosing a debt
 * needs, so nothing about the portfolio leaks into a payment screen.
 */
async function loadOpenDebts(clientId: number): Promise<void> {
    try {
        const response = await businessApi.payments.allocatable(clientId);

        // The endpoint already returns only debts with something outstanding.
        applyObligations.value = response.items;

        // Oldest first, which is the order the automatic application uses. An operator
        // overriding it is choosing to deviate deliberately.
        applyObligations.value.sort((a, b) => (a.due_on ?? '').localeCompare(b.due_on ?? ''));

        if (applyObligations.value.length > 0) {
            applyObligationId.value = applyObligations.value[0].obligation_id;
        }
    } catch (cause) {
        applyObligations.value = [];
        applyError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible cargar las obligaciones.';
    }
}

/**
 * Re-suggest the amount when the selected debt changes.
 *
 * A payment of 300000 against a 200000 debt then a 90000 one should propose 200000 and then
 * 90000. Leaving the first figure in place would make the second debt look like it needed
 * more than it does.
 */
watch(applyObligationId, () => {
    if (applyTarget.value === null) {
        return;
    }

    applyAmount.value = String(suggestedApplyAmount.value);
});

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
                                    <!--
                                        §17. The history is readable by anybody who may see
                                        payments; only the reversal needs `payments.allocate`.
                                    -->
                                    <AppButton
                                        v-if="!payment.is_voided"
                                        variant="ghost"
                                        size="sm"
                                        @click="openHistory(payment)"
                                    >
                                        Historial
                                    </AppButton>

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
                    <option v-for="option in vocabulary?.methods ?? []" :key="option.value" :value="option.value">
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
                    <option
                        v-for="row in applyObligations"
                        :key="row.obligation_id"
                        :value="row.obligation_id"
                    >
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
            :disabled="!reasonIsLongEnough(voidReason)"
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
                <!--
                    §6 of R3. This button was enabled by any non-empty reason while
                    `VoidPaymentRequest` requires ten characters, so one character armed a
                    destructive action and the server refused it after the round trip. The
                    check is now the shared one, the same every other reason dialog uses.
                -->
                <p class="cdh-form-hint">{{ REASON_MIN_LENGTH_HINT }}</p>
            </div>

            <AppAlert v-if="voidError" variant="danger" :title="voidError" />
        </AppModal>

        <!--
            §17. The payment's history.

            Every allocation the payment ever had, in one place: what it paid, which period
            and company, whether it still counts, and — when it does not — why and when.
            Reversed rows stay on the list on purpose. Removing them would present a
            payment that was applied and then un-applied as one that was never applied.
        -->
        <AppModal
            :open="historyTarget !== null"
            wide
            title="Historial del pago"
            @close="closeHistory"
        >
                <template v-if="historyTarget">
                <div class="cdh-stack-3">
                    <div class="cdh-pager__range">
                        {{ historyTarget.client_name }} · {{ fecha(historyTarget.received_on) }} ·
                        {{ pesos(historyTarget.amount_cop) }} · {{ historyTarget.method_label }}
                    </div>

                    <div class="cdh-stack-2">
                        <span>
                            Aplicado:
                            <strong>{{ pesos(historyTarget.allocated_amount_cop) }}</strong>
                        </span>
                        <span>
                            Sin aplicar:
                            <strong>{{ pesos(historyTarget.unallocated_amount_cop) }}</strong>
                        </span>
                        <span>{{ historyTarget.reconciliation_state_label }}</span>
                    </div>

                    <AppLoading v-if="historyBusy" label="Cargando el historial" />

                    <AppAlert v-if="historyError" variant="danger" :title="historyError" />

                    <AppEmptyState
                        v-else-if="historyAllocations.length === 0"
                        title="Este pago todavía no se ha aplicado a ninguna obligación"
                    />

                    <table v-else class="cdh-table">
                        <thead>
                            <tr>
                                <th data-label="Periodo">Periodo</th>
                                <th data-label="Empresa">Empresa</th>
                                <th data-label="Valor">Valor</th>
                                <th data-label="Estado">Estado</th>
                                <th data-label="Motivo">Motivo</th>
                                <th data-label="Acciones">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="allocation in historyAllocations"
                                :key="allocation.id"
                                :class="{ 'cdh-muted': allocation.is_reversed }"
                            >
                                <td data-label="Periodo">
                                    {{ allocation.obligation_label ?? '—' }}
                                    <span class="cdh-table__secondary">
                                        {{ fecha(allocation.due_on) }}
                                    </span>
                                </td>
                                <td data-label="Empresa">{{ allocation.company_name ?? '—' }}</td>
                                <td data-label="Valor" class="cdh-table__numeric">
                                    {{ pesos(allocation.amount_cop) }}
                                </td>
                                <td data-label="Estado">
                                    <span v-if="allocation.is_reversed" class="cdh-badge cdh-badge--muted">
                                        Revertida
                                    </span>
                                    <span v-else class="cdh-badge cdh-badge--ok">Activa</span>
                                    <span class="cdh-table__secondary">
                                        {{ fechaHora(allocation.created_at) }}
                                    </span>
                                    <span v-if="allocation.reversed_at" class="cdh-table__secondary">
                                        Revertida el {{ fechaHora(allocation.reversed_at) }}
                                    </span>
                                </td>
                                <td data-label="Motivo">
                                    {{ allocation.reversal_reason ?? '—' }}
                                </td>
                                <td data-label="Acciones" class="cdh-table__actions">
                                    <AppButton
                                        v-if="canAllocate && allocation.is_active && !historyTarget?.is_voided"
                                        variant="ghost"
                                        size="sm"
                                        @click="askReverse(allocation)"
                                    >
                                        Revertir
                                    </AppButton>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                </template>
        </AppModal>

        <!--
            The reversal is a separate confirmation rather than a second click on the row:
            it moves money back into the payment's unapplied balance and re-opens the debt,
            and neither is obvious from a button label.
        -->
        <AppModal
            :open="reversalTarget !== null"
            title="Revertir aplicación"
            @close="closeReverse"
        >
                <template v-if="reversalTarget">
                <div class="cdh-stack-3">
                    <p>
                        Se devolverán
                        <strong>{{ pesos(reversalTarget.amount_cop) }}</strong>
                        de
                        <strong>{{ historyTarget?.client_name }}</strong> al saldo sin aplicar, y
                        la obligación
                        <strong>{{ reversalTarget.obligation_label }}</strong>
                        volverá a estar pendiente.
                    </p>

                    <div>
                        <label class="cdh-form-label" for="reversal-reason">Motivo</label>
                        <textarea
                            id="reversal-reason"
                            v-model="reversalReason"
                            class="form-control"
                            rows="3"
                            required
                        ></textarea>
                        <!--
                            §7 of R3. The disabled state was already correct — the reversal
                            needs ten characters — but this said "sin motivo no se revierte",
                            which is a different rule. The reason *was* there; it was not yet
                            long enough, and a hint describing a rule the form does not apply
                            leaves a disabled button looking broken. The copy and the gate now
                            come from the same place.
                        -->
                        <p class="cdh-form-hint">{{ REASON_MIN_LENGTH_HINT }}</p>
                    </div>

                    <AppAlert v-if="reversalError" variant="danger" :title="reversalError" />

                    <AppButton
                        variant="danger"
                        :disabled="reversalBusy || reversalProblem !== null"
                        @click="confirmReverse"
                    >
                        Revertir aplicación
                    </AppButton>
                </div>
                </template>
        </AppModal>
</section>
</template>
