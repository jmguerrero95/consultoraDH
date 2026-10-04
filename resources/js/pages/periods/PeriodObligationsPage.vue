<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { onBeforeRouteUpdate } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import AppModal from '@/components/ui/AppModal.vue';
import { fecha, pesos, pesosDesdeTexto } from '@/composables/useFormatters';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';

import type {
    AdjustmentSummary,
    AdjustmentTypeKey,
    AdjustmentTypeOption,
    ObligationSummary,
    PeriodSummary,
} from '@/types/api';

/**
 * The obligations of one month.
 *
 * Every figure on this screen is derived: the amount billed is the snapshot the
 * generation wrote plus the adjustments recorded since, and what is left is that
 * minus the money applied. Nothing is stored as a balance and nothing is edited in
 * place, which is why a reversed allocation or a voided payment changes these
 * numbers without anybody touching them.
 *
 * So a row is not a form. To change what a client owes, an adjustment is recorded
 * and the row says so, with the reason.
 */

const props = defineProps<{ id: string }>();

const auth = useAuthStore();
const canAdjust = computed(() => auth.can('obligations.adjust'));

const period = ref<PeriodSummary | null>(null);
const obligations = ref<ObligationSummary[]>([]);
const page = ref(1);
const perPage = ref<number>(25);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(true);
const error = ref<string | null>(null);
const notice = ref<string | null>(null);

let requestId = 0;

async function load(): Promise<void> {
    const current = ++requestId;

    loading.value = true;
    error.value = null;

    try {
        const [periodPayload, obligationsPayload] = await Promise.all([
            businessApi.periods.show(Number(props.id)),
            businessApi.periods.obligations(Number(props.id), {
                page: page.value,
                per_page: perPage.value,
            }),
        ]);

        if (current !== requestId) {
            return;
        }

        period.value = periodPayload.period;
        obligations.value = obligationsPayload.items;
        total.value = obligationsPayload.pagination.total;
        lastPage.value = obligationsPayload.pagination.last_page;
    } catch (cause) {
        if (current !== requestId) {
            return;
        }

        obligations.value = [];
        error.value =
            cause instanceof ApiError ? cause.message : 'No fue posible cargar las obligaciones.';
    } finally {
        if (current === requestId) {
            loading.value = false;
        }
    }
}

onMounted(() => void load());
onBeforeRouteUpdate(() => void load());

watch([page, perPage], () => void load());

// --- Adjustments -------------------------------------------------------------

const adjustTarget = ref<ObligationSummary | null>(null);
const adjustType = ref('correction');
const adjustAmount = ref('');
const adjustReason = ref('');
const adjustBusy = ref(false);
const adjustError = ref<string | null>(null);

/**
 * The adjustment types, from the backend.
 *
 * The list was hardcoded here with three entries and omitted `credit`, so the type the API
 * accepted was not the type the screen offered. Each option carries the direction it accepts,
 * which is what lets the form state the amount the way an operator means it instead of
 * guessing a sign.
 */
const adjustmentTypes = ref<AdjustmentTypeOption[]>([]);
const ADJUSTMENT_TYPES = computed(() => adjustmentTypes.value);

/**
 * Load the types once.
 *
 * A failure is not announced: the form falls back to a short local list so an operator can
 * still record a correction, and the button that matters — Revertir — does not depend on it.
 * Losing the vocabulary must not cost somebody the ability to correct an amount.
 *
 * §55. The vocabulary endpoint is behind `obligations.adjust`, and this ran on arrival for
 * every account that could open the screen at all — so a Collections or Read Only account
 * reading a month's obligations asked for a list of types it has no authority to use, and
 * was answered 403 on a screen that otherwise works. It is fetched only for an account that
 * may actually record an adjustment, which is the only account whose dialog uses it.
 */
async function loadAdjustmentVocabulary(): Promise<void> {
    if (!canAdjust.value) {
        return;
    }

    try {
        adjustmentTypes.value = (await businessApi.obligations.adjustmentVocabulary()).types;
    } catch {
        adjustmentTypes.value = [
            { value: 'correction', label: 'Corrección', direction: 'either' },
            { value: 'discount', label: 'Descuento', direction: 'decrease' },
            { value: 'surcharge', label: 'Recargo', direction: 'increase' },
            { value: 'credit', label: 'Crédito', direction: 'decrease' },
        ];
    }
}

/**
 * Fetched when the permission appears, not only when the screen mounts.
 *
 * A `watch` rather than a call in `onMounted`, because the account's permissions are not
 * always known at mount time and a one-shot call would then be permanently skipped for the
 * one account that is allowed to adjust.
 */
watch(canAdjust, (allowed) => {
    if (allowed) {
        void loadAdjustmentVocabulary();
    }
}, { immediate: true });

/**
 * The sign of the adjustment as it will be recorded.
 *
 * A discount is entered as a positive number because that is how it is written down:
 * "a discount of fifty thousand" is not "minus fifty thousand", and asking an
 * operator to type a minus sign invites a discounted amount to be added instead.
 */
/**
 * The signed amount as it will be recorded.
 *
 * ## The parser
 *
 * `pesosDesdeTexto`, the shared one. This used to use `Number.parseInt` after deleting dots
 * and spaces, which made it the only money field in the application that accepted
 * `"235000.50"` and stored `235000`: fifty pesos silently lost, and the field looked like it
 * had worked. Every other money input rejects centavos.
 *
 * ## The sign
 *
 * It used to be forced here: `discount` negated, everything else made positive. That made a
 * negative correction unrepresentable — you could not type `-5000` for a correction, because
 * the sign was flipped to `+5000` — and it let a surcharge recorded as `-50000` become
 * `+50000`, which is arithmetic disagreeing with its own label.
 *
 * Now the direction is decided by the type, and the interface states the amount the way an
 * operator means it. `correction` takes the sign as typed; `discount` and `credit` are
 * entered as a positive figure and reduced, because that is how a discount is written down;
 * `surcharge` is entered as a positive figure and increases.
 */
const adjustDelta = computed(() => {
    const parsed = pesosDesdeTexto(adjustAmount.value);

    if (parsed === null || parsed === 0) {
        return null;
    }

    const type = adjustType.value as AdjustmentTypeKey;

    if (type === 'discount' || type === 'credit') {
        return -Math.abs(parsed);
    }

    if (type === 'surcharge') {
        return Math.abs(parsed);
    }

    // Correction: the sign is the operator's.
    return parsed;
});

const wouldGoNegative = computed(() => {
    if (adjustTarget.value === null || adjustDelta.value === null) {
        return false;
    }

    return adjustTarget.value.effective_amount_cop + adjustDelta.value < 0;
});

function openAdjust(obligation: ObligationSummary): void {
    adjustTarget.value = obligation;
    adjustType.value = ADJUSTMENT_TYPES.value[0]?.value ?? 'correction';
    adjustAmount.value = '';
    adjustReason.value = '';
    adjustError.value = null;
}

async function submitAdjustment(): Promise<void> {
    if (adjustTarget.value === null || adjustDelta.value === null) {
        adjustError.value = 'Escriba un valor en pesos diferentes de cero.';
        return;
    }

    adjustBusy.value = true;
    adjustError.value = null;

    try {
        const result = await businessApi.obligations.adjust(adjustTarget.value.id, {
            type: adjustType.value,
            delta_cop: adjustDelta.value,
            reason: adjustReason.value,
        });

        adjustTarget.value = null;
        notice.value = result.message;
        await load();
    } catch (cause) {
        adjustError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible registrar el ajuste.';
    } finally {
        adjustBusy.value = false;
    }
}

const adjustmentsFor = ref<ObligationSummary | null>(null);
const adjustments = ref<AdjustmentSummary[]>([]);
const adjustmentsBusy = ref(false);
const adjustmentsError = ref<string | null>(null);

async function openAdjustments(obligation: ObligationSummary): Promise<void> {
    adjustmentsFor.value = obligation;
    adjustments.value = [];
    adjustmentsBusy.value = true;
    adjustmentsError.value = null;

    try {
        adjustments.value = (await businessApi.obligations.adjustments(obligation.id)).items;
    } catch (cause) {
        adjustmentsError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible cargar los ajustes.';
    } finally {
        adjustmentsBusy.value = false;
    }
}

const reversalTarget = ref<AdjustmentSummary | null>(null);
const reversalReason = ref('');
const reversalBusy = ref(false);
const reversalError = ref<string | null>(null);

function askReversal(adjustment: AdjustmentSummary): void {
    reversalTarget.value = adjustment;
    reversalReason.value = '';
    reversalError.value = null;
}

async function reverseAdjustment(): Promise<void> {
    if (reversalTarget.value === null) {
        return;
    }

    reversalBusy.value = true;
    reversalError.value = null;

    try {
        const result = await businessApi.obligations.reverseAdjustment(
            reversalTarget.value.id,
            reversalReason.value,
        );

        reversalTarget.value = null;
        notice.value = result.message;

        // Both the obligation figures and the list of adjustments are now stale.
        if (adjustmentsFor.value !== null) {
            await openAdjustments(adjustmentsFor.value);
        }

        await load();
    } catch (cause) {
        reversalError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible revertir el ajuste.';
    } finally {
        reversalBusy.value = false;
    }
}

const settlementBadge: Record<string, string> = {
    paid: 'cdh-badge--success',
    partial: 'cdh-badge--warning',
    pending: 'cdh-badge--neutral',
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
                <h1 class="cdh-page-header__title">Obligaciones</h1>
                <p class="cdh-page-header__subtitle">
                    {{ period ? period.label : 'Cargando…' }} — {{ rangeLabel }}
                </p>
            </div>

            <AppButton
                variant="secondary"
                :to="{ name: 'periods' }"
                icon="bi-arrow-left"
            >
                Volver a periodos
            </AppButton>
        </header>

        <AppAlert v-if="error" variant="danger" :title="error" class="mb-4" />
        <AppAlert v-if="notice" variant="success" :title="notice" class="mb-4" />

        <AppLoading v-if="loading && obligations.length === 0" label="Cargando obligaciones" />

        <AppEmptyState
            v-else-if="!error && obligations.length === 0"
            title="Sin obligaciones en este periodo"
            description="Genere las obligaciones desde la pantalla de periodos."
        />

        <template v-else>
            <div class="cdh-table-wrap">
                <table class="cdh-table">
                    <caption class="cdh-visually-hidden">
                        Obligaciones del periodo
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">Cliente</th>
                            <th scope="col" class="cdh-table__wide">Empresa</th>
                            <th scope="col">Valor base</th>
                            <th scope="col">Ajustes</th>
                            <th scope="col">Total</th>
                            <th scope="col">Pagado</th>
                            <th scope="col">Saldo</th>
                            <th scope="col">Vence</th>
                            <th scope="col">Estado</th>
                            <th scope="col"><span class="cdh-visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="obligation in obligations" :key="obligation.id">
                            <td data-label="Cliente">
                                <span class="cdh-table__primary">{{ obligation.client_name }}</span>
                            </td>
                            <td data-label="Empresa" class="cdh-table__wide">
                                {{ obligation.company_name }}
                            </td>
                            <td data-label="Valor base" class="cdh-table__numeric">
                                {{ pesos(obligation.base_amount_cop) }}
                            </td>
                            <td data-label="Ajustes" class="cdh-table__numeric">
                                <span v-if="obligation.adjustments_cop === 0" class="cdh-table__secondary">
                                    —
                                </span>
                                <span
                                    v-else
                                    :class="obligation.adjustments_cop < 0 ? 'cdh-danger' : 'cdh-success'"
                                >
                                    {{ pesos(obligation.adjustments_cop) }}
                                </span>
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
                                    {{ fecha(obligation.due_on) }}
                                </span>
                            </td>
                            <td data-label="Estado">
                                <span
                                    class="cdh-badge"
                                    :class="settlementBadge[obligation.settlement_state] ?? 'cdh-badge--neutral'"
                                >
                                    {{ obligation.settlement_state_label ?? obligation.settlement_state }}
                                </span>
                            </td>
                            <td data-label="Acciones" class="cdh-table__actions">
                                <div class="cdh-stack-2">
                                    <AppButton
                                        v-if="canAdjust"
                                        variant="ghost"
                                        size="sm"
                                        @click="openAdjustments(obligation)"
                                    >
                                        Ajustes
                                    </AppButton>
                                    <AppButton
                                        v-if="canAdjust && obligation.balance_cop > 0"
                                        variant="secondary"
                                        size="sm"
                                        @click="openAdjust(obligation)"
                                    >
                                        Ajustar
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

                <AppButton
                    variant="secondary"
                    :disabled="page >= lastPage"
                    @click="page += 1"
                >
                    Siguiente
                </AppButton>
            </nav>
        </template>

        <!-- Recording an adjustment -->
        <AppModal
            :open="adjustTarget !== null"
            title="Ajustar obligación"
            confirm-label="Registrar ajuste"
            :busy="adjustBusy"
            :disabled="
                adjustDelta === null ||
                adjustReason.trim() === '' ||
                wouldGoNegative
            "
            @confirm="submitAdjustment"
            @cancel="adjustTarget = null"
        >
            <p class="cdh-form-hint">
                El valor generado no se modifica. Se registra una diferencia con su motivo, y
                el total pasa a ser el valor base más esta diferencia.
            </p>

            <div class="mb-3">
                <label class="cdh-form-label" for="adjust-type">Tipo</label>
                <select id="adjust-type" v-model="adjustType" class="form-control">
                    <option v-for="type in ADJUSTMENT_TYPES" :key="type.value" :value="type.value">
                        {{ type.label }}
                    </option>
                </select>
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="adjust-amount">Valor (pesos)</label>
                <input
                    id="adjust-amount"
                    v-model="adjustAmount"
                    class="form-control"
                    type="text"
                    inputmode="numeric"
                    placeholder="50000"
                />
                <p class="cdh-form-hint">
                    Un número entero, sin decimales. El descuento se guarda en negativo.
                </p>
            </div>

            <div class="mb-3">
                <label class="cdh-form-label" for="adjust-reason">Motivo</label>
                <textarea
                    id="adjust-reason"
                    v-model="adjustReason"
                    class="form-control"
                    rows="3"
                    required
                ></textarea>
                <p class="cdh-form-hint">Queda en la auditoría. Sin motivo no se ajusta.</p>
            </div>

            <AppAlert
                v-if="wouldGoNegative"
                variant="danger"
                title="El total quedaría en negativo"
                description="Una obligación no puede valer menos de cero. Revise el valor."
            />

            <AppAlert v-if="adjustError" variant="danger" :title="adjustError" />
        </AppModal>

        <!-- The adjustment history of one obligation -->
        <AppModal
            :open="adjustmentsFor !== null"
            title="Ajustes de la obligación"
            confirm-label="Cerrar"
            @confirm="adjustmentsFor = null"
            @cancel="adjustmentsFor = null"
            wide
        >
            <p v-if="adjustmentsFor" class="cdh-form-hint">
                Total actual: <strong>{{ pesos(adjustmentsFor.effective_amount_cop) }}</strong>
                sobre una base de {{ pesos(adjustmentsFor.base_amount_cop) }}.
            </p>

            <AppLoading v-if="adjustmentsBusy" label="Cargando ajustes" />

            <template v-else>
                <div v-if="adjustments.length > 0" class="cdh-table-wrap">
                    <table class="cdh-table">
                        <caption class="cdh-visually-hidden">Ajustes registrados</caption>
                        <thead>
                            <tr>
                                <th scope="col">Tipo</th>
                                <th scope="col">Diferencia</th>
                                <th scope="col">Motivo</th>
                                <th scope="col">Fecha</th>
                                <th scope="col">Estado</th>
                                <th scope="col"><span class="cdh-visually-hidden">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="adjustment in adjustments" :key="adjustment.id">
                                <td data-label="Tipo">{{ adjustment.type_label }}</td>
                                <td
                                    data-label="Diferencia"
                                    class="cdh-table__numeric"
                                    :class="adjustment.delta_cop < 0 ? 'cdh-danger' : 'cdh-success'"
                                >
                                    {{ pesos(adjustment.delta_cop) }}
                                </td>
                                <td data-label="Motivo" class="cdh-table__wide">
                                    {{ adjustment.reason }}
                                    <span v-if="adjustment.reverses_adjustment_id" class="cdh-table__secondary">
                                        Revierte el ajuste #{{ adjustment.reverses_adjustment_id }}
                                    </span>
                                </td>
                                <td data-label="Fecha">{{ fecha(adjustment.created_at) }}</td>
                                <td data-label="Estado">
                                    <!--
                                        The state comes from the ledger's relation, not from
                                        a `reversed_at` column that never existed. An original
                                        that has been undone says so, and keeps its reason.
                                    -->
                                    <span
                                        v-if="adjustment.is_reversed"
                                        class="cdh-badge cdh-badge--neutral"
                                        :title="adjustment.reversal_reason ?? undefined"
                                    >
                                        revertido
                                    </span>
                                    <span v-else-if="adjustment.is_reversal" class="cdh-badge cdh-badge--info">
                                        reversión
                                    </span>
                                    <span v-else class="cdh-badge cdh-badge--info">vigente</span>
                                </td>
                                <td data-label="Acciones" class="cdh-table__actions">
                                    <!--
                                        `can_reverse` is published rather than derived here.
                                        Deriving it from two booleans is what made the screen
                                        offer a second reversal that the database then
                                        refused.
                                    -->
                                    <AppButton
                                        v-if="canAdjust && adjustment.can_reverse"
                                        variant="ghost"
                                        size="sm"
                                        @click="askReversal(adjustment)"
                                    >
                                        Revertir
                                    </AppButton>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-else class="cdh-text-muted">
                    Esta obligación no tiene ajustes. Lo que se facturó es lo que se generó.
                </p>
            </template>

            <AppAlert v-if="adjustmentsError" variant="danger" :title="adjustmentsError" />
        </AppModal>

        <!-- Reversing an adjustment: kept as a new record, never a deletion -->
        <AppModal
            :open="reversalTarget !== null"
            title="Revertir ajuste"
            confirm-label="Revertir"
            :busy="reversalBusy"
            :disabled="reversalReason.trim() === ''"
            destructive
            @confirm="reverseAdjustment"
            @cancel="reversalTarget = null"
        >
            <p>
                Se registra un ajuste nuevo de {{ pesos(reversalTarget?.delta_cop) }} que
                cancela el anterior. Ni el ajuste original ni este se borran: los dos quedan
                en la historia, que es lo que permite entender qué pasó.
            </p>

            <div class="mb-3">
                <label class="cdh-form-label" for="reversal-reason">Motivo</label>
                <textarea
                    id="reversal-reason"
                    v-model="reversalReason"
                    class="form-control"
                    rows="3"
                    required
                ></textarea>
                <p class="cdh-form-hint">Queda en la auditoría. Sin motivo no se revierte.</p>
            </div>

            <AppAlert v-if="reversalError" variant="danger" :title="reversalError" />
        </AppModal>
    </section>
</template>
