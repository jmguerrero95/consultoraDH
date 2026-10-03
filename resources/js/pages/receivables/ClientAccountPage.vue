<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { onBeforeRouteUpdate } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { fecha, pesos } from '@/composables/useFormatters';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';

import type { ClientAccountPayload } from '@/types/api';

/**
 * La cuenta de un cliente: what they owe, what they paid, and when.
 *
 * A statement without a PDF. It answers the three questions somebody actually has
 * about a debt — how much, since when, and which months — because "you are three
 * months behind" cannot be acted on, while "you owe October, November and December"
 * can.
 *
 * The months are listed by name rather than summarised as a count for the same
 * reason.
 */

const props = defineProps<{ id: string }>();

const asOf = ref('');
const account = ref<ClientAccountPayload | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

let requestId = 0;

async function load(): Promise<void> {
    const current = ++requestId;

    loading.value = true;
    error.value = null;

    try {
        const payload = await businessApi.receivables.clientAccount(
            Number(props.id),
            asOf.value === '' ? null : asOf.value,
        );

        if (current !== requestId) {
            return;
        }

        account.value = payload;
    } catch (cause) {
        if (current !== requestId) {
            return;
        }

        account.value = null;
        error.value =
            cause instanceof ApiError ? cause.message : 'No fue posible cargar la cuenta.';
    } finally {
        if (current === requestId) {
            loading.value = false;
        }
    }
}

onMounted(() => void load());
onBeforeRouteUpdate(() => void load());
watch(asOf, () => void load());

const stateBadge: Record<string, string> = {
    paid: 'cdh-badge--success',
    partial: 'cdh-badge--warning',
    pending: 'cdh-badge--neutral',
};

const hasDebt = computed(() => (account.value?.summary.outstanding_balance_cop ?? 0) > 0);
</script>

<template>
    <section class="cdh-content">
        <header class="cdh-page-header">
            <div>
                <h1 class="cdh-page-header__title">Cuenta del cliente</h1>
                <p class="cdh-page-header__subtitle">
                    <template v-if="account">
                        {{ account.client.full_name }} — {{ account.client.document_label }}
                    </template>
                    <template v-else>Cargando…</template>
                </p>
            </div>

            <div class="cdh-stack-2">
                <label class="cdh-visually-hidden" for="account-as-of">Corte de la consulta</label>
                <input
                    id="account-as-of"
                    v-model="asOf"
                    class="form-control form-control-sm"
                    type="date"
                />
                <AppButton
                    variant="secondary"
                    :to="{ name: 'clients.show', params: { id } }"
                    icon="bi-person-vcard"
                >
                    Ficha del cliente
                </AppButton>
            </div>
        </header>

        <AppAlert v-if="error" variant="danger" :title="error" class="mb-4" />

        <AppLoading v-if="loading && account === null" label="Cargando la cuenta" />

        <template v-else-if="account">
            <div class="cdh-grid-stats mb-4">
                <article class="cdh-stat">
                    <p class="cdh-stat__label">
                        <i class="bi bi-cash-coin" aria-hidden="true" />
                        <span>Saldo pendiente</span>
                    </p>
                    <p
                        class="cdh-stat__value"
                        :class="{ 'cdh-danger': hasDebt }"
                    >
                        {{ pesos(account.summary.outstanding_balance_cop) }}
                    </p>
                    <p class="cdh-stat__hint">
                        {{ account.summary.open_obligations_count }} obligaciones abiertas
                    </p>
                </article>

                <article class="cdh-stat">
                    <p class="cdh-stat__label">
                        <i class="bi bi-exclamation-triangle" aria-hidden="true" />
                        <span>Vencido</span>
                    </p>
                    <p
                        class="cdh-stat__value"
                        :class="{ 'cdh-danger': account.summary.overdue_balance_cop > 0 }"
                    >
                        {{ pesos(account.summary.overdue_balance_cop) }}
                    </p>
                    <p class="cdh-stat__hint">
                        {{ account.summary.overdue_obligations_count }} obligaciones
                    </p>
                </article>

                <article class="cdh-stat">
                    <p class="cdh-stat__label">
                        <i class="bi bi-check2-circle" aria-hidden="true" />
                        <span>Pagado</span>
                    </p>
                    <p class="cdh-stat__value">{{ pesos(account.summary.total_paid_cop) }}</p>
                    <p class="cdh-stat__hint">
                        Facturado {{ pesos(account.summary.total_effective_obligations_cop) }}
                    </p>
                </article>

                <article class="cdh-stat">
                    <p class="cdh-stat__label">
                        <i class="bi bi-wallet2" aria-hidden="true" />
                        <span>Anticipo sin aplicar</span>
                    </p>
                    <p class="cdh-stat__value">
                        {{ pesos(account.summary.unallocated_credit_cop) }}
                    </p>
                    <p class="cdh-form-hint">
                        Dinero recibido que todavía no se ha aplicado a una obligación.
                    </p>
                </article>
            </div>

            <AppAlert
                v-if="account.summary.owed_periods.length > 0"
                variant="info"
                title="Periodos que debe"
                :description="account.summary.owed_periods.join(', ')"
                class="mb-4"
            />

            <AppEmptyState
                v-if="account.obligations.length === 0"
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
                            <th scope="col">Valor base</th>
                            <th scope="col">Ajustes</th>
                            <th scope="col">Total</th>
                            <th scope="col">Pagado</th>
                            <th scope="col">Saldo</th>
                            <th scope="col">Vence</th>
                            <th scope="col">Estado</th>
                            <th scope="col">Antigüedad</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="obligation in account.obligations" :key="obligation.id">
                            <td data-label="Periodo">
                                <span class="cdh-table__primary">{{ obligation.period_label }}</span>
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
                                    :class="stateBadge[obligation.settlement_state] ?? 'cdh-badge--neutral'"
                                >
                                    {{ obligation.settlement_state_label }}
                                </span>
                            </td>
                            <td data-label="Antigüedad">
                                <span v-if="obligation.balance_cop === 0" class="cdh-table__secondary">
                                    —
                                </span>
                                <template v-else>
                                    {{ obligation.aging_bucket_label }}
                                    <span v-if="obligation.days_late > 0" class="cdh-table__secondary">
                                        {{ obligation.days_late }} días
                                    </span>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="cdh-form-hint mt-3">
                Consulta al día de {{ fecha(account.as_of) }}. Las cifras se calculan en el
                momento: no hay saldos guardados que puedan quedar desactualizados.
            </p>
        </template>
    </section>
</template>
