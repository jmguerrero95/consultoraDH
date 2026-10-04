<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import AppModal from '@/components/ui/AppModal.vue';
import { fechaHora, mesActual, pesos } from '@/composables/useFormatters';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';

import type {
    GenerationPreviewPayload,
    GenerationResultPayload,
    PeriodSummary,
} from '@/types/api';

/**
 * Los periodos mensuales.
 *
 * A period is a decision: which month the system bills, whether it may still be
 * changed, and how much it has billed and collected. So the screen is built around
 * that decision rather than around a table of rows.
 *
 * Two things are deliberately absent. Nothing here invents a cutoff or a rate: when
 * the configuration for a month is missing, generation says so and writes nothing,
 * because a guess would be a debt nobody agreed to. And nothing here deletes a month
 * or edits one that has been generated; a month that was billed wrongly is corrected
 * through its obligations, which is a record of what happened rather than an edit of
 * what the system thought happened.
 */

const auth = useAuthStore();

const canCreate = computed(() => auth.can('periods.create'));
const canClose = computed(() => auth.can('periods.close'));
const canReopen = computed(() => auth.can('periods.reopen'));
const canGenerate = computed(() => auth.can('obligations.generate'));
const canSeeObligations = computed(() => auth.can('obligations.view'));

/**
 * Whether this account may see what a period is worth.
 *
 * Mirrors the server's rule exactly: `obligations.view` **or** `obligations.generate`.
 * Somebody who may write a month's obligations has to see what they wrote; `periods.view`
 * alone is not enough, and that is the whole point of §37.
 */
const puedeVerDinero = computed(
    () => auth.can('obligations.view') || auth.can('obligations.generate'),
);

// --- Data --------------------------------------------------------------------
const periods = ref<PeriodSummary[]>([]);

/**
 * Pagination over the months.
 *
 * §38. The backend paginates at twenty-five and this page sent no page number and rendered
 * no navigation, so the first page was all there ever was. A monthly financial application
 * passes twenty-five months within two years, at which point the oldest months — the ones
 * most likely to be under discussion — became unreachable with no indication that anything
 * was missing.
 */
const page = ref(1);
const perPage = ref(25);
const total = ref(0);
const lastPage = ref(1);

const rangeLabel = computed(() => {
    if (total.value === 0) {
        return '—';
    }

    const desde = (page.value - 1) * perPage.value + 1;
    const hasta = Math.min(total.value, page.value * perPage.value);

    return `${desde}–${hasta} de ${total.value}`;
});
const current = ref<PeriodSummary | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const notice = ref<string | null>(null);
const busyPeriodId = ref<number | null>(null);

let requestId = 0;

async function load(): Promise<void> {
    const current_request = ++requestId;

    loading.value = true;
    error.value = null;

    try {
        const payload = await businessApi.periods.list({ page: page.value, per_page: perPage.value });

        // A response that arrived after a newer request started is stale.
        if (current_request !== requestId) {
            return;
        }

        periods.value = payload.items;
        total.value = payload.pagination.total;
        lastPage.value = Math.max(1, payload.pagination.last_page);

        // Deleting or opening something can empty the last page.
        if (page.value > lastPage.value) {
            page.value = lastPage.value;
        }
        current.value = payload.current;
    } catch (cause) {
        if (current_request !== requestId) {
            return;
        }

        periods.value = [];
        error.value =
            cause instanceof ApiError ? cause.message : 'No fue posible cargar los periodos.';
    } finally {
        if (current_request === requestId) {
            loading.value = false;
        }
    }
}

onMounted(() => void load());

// --- Opening a month ---------------------------------------------------------
const openDialog = ref(false);
const newMonth = ref(mesActual());
const openBusy = ref(false);
const openError = ref<string | null>(null);

function openPeriodDialog(): void {
    newMonth.value = mesActual();
    openError.value = null;
    openDialog.value = true;
}

async function createPeriod(): Promise<void> {
    openBusy.value = true;
    openError.value = null;

    try {
        const result = await businessApi.periods.open(newMonth.value);

        openDialog.value = false;
        notice.value = result.message;
        await load();
    } catch (cause) {
        openError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible abrir el periodo.';
    } finally {
        openBusy.value = false;
    }
}

// --- Generating --------------------------------------------------------------
const preview = ref<GenerationPreviewPayload | null>(null);
const previewOpen = ref(false);
const previewBusy = ref(false);
const previewError = ref<string | null>(null);
const generateBusy = ref(false);

async function openPreview(period: PeriodSummary): Promise<void> {
    previewBusy.value = true;
    previewError.value = null;
    preview.value = null;
    previewOpen.value = true;

    try {
        preview.value = await businessApi.periods.previewObligations(period.id);
    } catch (cause) {
        previewError.value =
            cause instanceof ApiError
                ? cause.message
                : 'No fue posible calcular la generación.';
    } finally {
        previewBusy.value = false;
    }
}

async function generate(): Promise<void> {
    if (preview.value === null) {
        return;
    }

    generateBusy.value = true;
    previewError.value = null;

    try {
        const result: GenerationResultPayload = await businessApi.periods.generateObligations(
            preview.value.period.id,
        );

        previewOpen.value = false;
        notice.value = result.message;
        await load();
    } catch (cause) {
        previewError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible generar las obligaciones.';
    } finally {
        generateBusy.value = false;
    }
}

const plan = computed(() => preview.value?.preview ?? null);

const blockedPreview = computed(() => (plan.value?.blockers ?? []).length > 0);

// --- Closing and reopening ---------------------------------------------------
const closeTarget = ref<PeriodSummary | null>(null);
const closeBusy = ref(false);
const closeError = ref<string | null>(null);

function askClose(period: PeriodSummary): void {
    closeTarget.value = period;
    closeError.value = null;
}

async function closePeriod(): Promise<void> {
    if (closeTarget.value === null) {
        return;
    }

    closeBusy.value = true;
    closeError.value = null;

    try {
        const result = await businessApi.periods.close(closeTarget.value.id);

        closeTarget.value = null;
        notice.value = result.message;
        await load();
    } catch (cause) {
        closeError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible cerrar el periodo.';
    } finally {
        closeBusy.value = false;
    }
}

const reopenTarget = ref<PeriodSummary | null>(null);
const reopenReason = ref('');
const reopenBusy = ref(false);
const reopenError = ref<string | null>(null);

function askReopen(period: PeriodSummary): void {
    reopenTarget.value = period;
    // Empty on purpose: the reason is the audit trail, and asking for it again
    // would be the only way it is ever wrong.
    reopenReason.value = '';
    reopenError.value = null;
}

async function reopenPeriod(): Promise<void> {
    if (reopenTarget.value === null) {
        return;
    }

    reopenBusy.value = true;
    reopenError.value = null;

    try {
        const result = await businessApi.periods.reopen(reopenTarget.value.id, reopenReason.value);

        reopenTarget.value = null;
        notice.value = result.message;
        await load();
    } catch (cause) {
        reopenError.value =
            cause instanceof ApiError ? cause.message : 'No fue posible reabrir el periodo.';
    } finally {
        reopenBusy.value = false;
    }
}

/**
 * The four buckets, read rather than derived.
 *
 * The previous sentence computed "already exists" as
 * `candidate_count - creatable_count - blocker_count`, and `blocker_count` was a count of
 * **findings**: a single candidate missing both a rate and a cutoff contributed two, so the
 * subtraction could produce a negative number of existing obligations on a screen whose job is
 * to say what is about to happen.
 *
 * The four published buckets are disjoint and sum to `candidate_count`, so nothing here has
 * to be inferred.
 */
const generationSummary = computed(() => {
    if (plan.value === null) {
        return '';
    }

    const {
        creatable_count,
        existing_candidate_count,
        blocked_candidate_count,
        creatable_amount_cop,
    } = plan.value;

    const partes = [
        `${creatable_count} ${creatable_count === 1 ? 'obligación nueva' : 'obligaciones nuevas'}`,
    ];

    if (existing_candidate_count > 0) {
        partes.push(`${existing_candidate_count} ya existentes`);
    }

    if (blocked_candidate_count > 0) {
        partes.push(`${blocked_candidate_count} bloqueadas`);
    }

    // The amount that would actually be written, not the month's resolved total.
    return `${partes.join(' · ')} — ${pesos(creatable_amount_cop)}`;
});
</script>

<template>
    <section class="cdh-content">
        <header class="cdh-page-header">
            <div>
                <h1 class="cdh-page-header__title">Periodos</h1>
                <p class="cdh-page-header__subtitle">
                    <template v-if="current">
                        Periodo actual: <strong>{{ current.label }}</strong>
                        <span v-if="current.status === 'closed'" class="cdh-badge cdh-badge--neutral">
                            cerrado
                        </span>
                    </template>
                    <template v-else>No hay un periodo abierto.</template>
                </p>
            </div>

            <AppButton v-if="canCreate" icon="bi-plus-lg" @click="openPeriodDialog">
                Abrir periodo
            </AppButton>
        </header>

        <AppAlert v-if="error" variant="danger" :title="error" class="mb-4" />
        <AppAlert v-if="notice" variant="success" :title="notice" class="mb-4" />

        <AppLoading v-if="loading && periods.length === 0" label="Cargando periodos" />

        <AppEmptyState
            v-else-if="!error && periods.length === 0"
            title="Aún no hay periodos"
            description="Abra el mes que va a facturar para empezar a generar las obligaciones."
            :action-label="canCreate ? 'Abrir periodo' : undefined"
            @action="canCreate && openPeriodDialog()"
        />

        <template v-else>
            <div class="cdh-grid-stats mb-4">
                <article class="cdh-stat">
                    <p class="cdh-stat__label">
                        <i class="bi bi-calendar3" aria-hidden="true" />
                        <span>Periodo actual</span>
                    </p>
                    <p class="cdh-stat__value">{{ current ? current.label : '—' }}</p>
                    <p class="cdh-stat__hint">
                        {{ current ? current.status_label : 'Ninguno abierto' }}
                    </p>
                </article>

                <article class="cdh-stat">
                    <p class="cdh-stat__label">
                        <i class="bi bi-file-earmark-text" aria-hidden="true" />
                        <span>Por facturar</span>
                    </p>
                    <p class="cdh-stat__value">
                        {{ puedeVerDinero ? pesos(current?.total_balance_cop) : '—' }}
                    </p>
                    <p class="cdh-stat__hint">
                        <!--
                            No permission means no figure, and an em dash rather than a
                            zero: "0 pesos por facturar" is a financial statement, and
                            `periods.view` alone does not entitle anybody to make one.
                        -->
                        <template v-if="puedeVerDinero">
                            {{ current?.obligation_count ?? 0 }} obligaciones en el periodo
                        </template>
                        <template v-else>Requiere permiso para ver las obligaciones</template>
                    </p>
                </article>

                <article class="cdh-stat">
                    <p class="cdh-stat__label">
                        <i class="bi bi-cash-coin" aria-hidden="true" />
                        <span>Recaudado</span>
                    </p>
                    <p class="cdh-stat__value">{{ pesos(current ? current.total_paid_cop : 0) }}</p>
                    <p class="cdh-stat__hint">Del periodo actual</p>
                </article>
            </div>

            <div class="cdh-table-wrap">
                <table class="cdh-table">
                    <caption class="cdh-visually-hidden">Periodos mensuales</caption>
                    <thead>
                        <tr>
                            <th scope="col">Periodo</th>
                            <th scope="col">Estado</th>
                            <th scope="col" class="cdh-table__wide">Generación</th>
                            <th scope="col">Obligaciones</th>
                            <th scope="col">Facturado</th>
                            <th scope="col">Recaudado</th>
                            <th scope="col">Saldo</th>
                            <th scope="col"><span class="cdh-visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="period in periods" :key="period.id">
                            <td data-label="Periodo">
                                <span class="cdh-table__primary">{{ period.label }}</span>
                                <span class="cdh-table__secondary">
                                    {{ period.starts_on }} a {{ period.ends_on_exclusive }}
                                </span>
                            </td>
                            <td data-label="Estado">
                                <span
                                    class="cdh-badge"
                                    :class="period.status === 'open' ? 'cdh-badge--success' : 'cdh-badge--neutral'"
                                >
                                    {{ period.status_label }}
                                </span>
                            </td>
                            <td data-label="Generación" class="cdh-table__wide">
                                <span v-if="period.generation_performed_at">
                                    {{ fechaHora(period.generation_performed_at) }}
                                </span>
                                <span v-else class="cdh-table__secondary">Sin generar</span>
                            </td>
                            <!--
                                §37. The whole financial block is conditional. The server
                                omits these keys without an obligations permission, and a
                                column of zeroes would be a claim that every month is worth
                                nothing.
                            -->
                            <template v-if="puedeVerDinero">
                                <td data-label="Obligaciones" class="cdh-table__numeric">
                                    {{ period.obligation_count }}
                                </td>
                                <td data-label="Facturado" class="cdh-table__numeric">
                                    {{ pesos(period.total_effective_cop) }}
                                </td>
                                <td data-label="Aplicado" class="cdh-table__numeric">
                                    {{ pesos(period.total_paid_cop) }}
                                </td>
                                <td data-label="Saldo" class="cdh-table__numeric">
                                    {{ pesos(period.total_balance_cop) }}
                                </td>
                            </template>
                            <td v-else colspan="4" class="cdh-table__secondary">
                                Sin permiso para ver las cifras del periodo
                            </td>
                            <td data-label="Acciones" class="cdh-table__actions">
                                <div class="cdh-stack-2">
                                    <AppButton
                                        v-if="canGenerate && period.status === 'open'"
                                        variant="secondary"
                                        size="sm"
                                        :busy="busyPeriodId === period.id"
                                        @click="openPreview(period)"
                                    >
                                        Generar
                                    </AppButton>

                                    <AppButton
                                        v-if="canSeeObligations"
                                        variant="ghost"
                                        size="sm"
                                        :to="{
                                            name: 'periods.obligations',
                                            params: { id: period.id },
                                        }"
                                    >
                                        Obligaciones
                                    </AppButton>

                                    <AppButton
                                        v-if="canClose && period.status === 'open'"
                                        variant="ghost"
                                        size="sm"
                                        @click="askClose(period)"
                                    >
                                        Cerrar
                                    </AppButton>

                                    <AppButton
                                        v-if="canReopen && period.status === 'closed'"
                                        variant="ghost"
                                        size="sm"
                                        @click="askReopen(period)"
                                    >
                                        Reabrir
                                    </AppButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- §38: without this the months past the first page do not exist. -->
                <nav class="cdh-pager" aria-label="Paginación de periodos">
                    <p class="cdh-pager__range">{{ rangeLabel }}</p>
                    <AppButton
                        variant="ghost"
                        size="sm"
                        :disabled="page <= 1"
                        @click="page -= 1"
                    >
                        Anterior
                    </AppButton>
                    <AppButton
                        variant="ghost"
                        size="sm"
                        :disabled="page >= lastPage"
                        @click="page += 1"
                    >
                        Siguiente
                    </AppButton>
                </nav>
            </div>
        </template>

        <!-- Opening a month -->
        <AppModal
            :open="openDialog"
            title="Abrir periodo"
            confirm-label="Abrir periodo"
            :busy="openBusy"
            @confirm="createPeriod"
            @cancel="openDialog = false"
        >
            <div class="mb-3">
                <label class="cdh-form-label" for="period-month">Mes</label>
                <input
                    id="period-month"
                    v-model="newMonth"
                    class="form-control"
                    type="month"
                    required
                />
                <p class="cdh-form-hint">El mes que el sistema va a facturar.</p>
            </div>

            <p class="cdh-form-hint mb-0">
                Abrir un periodo no genera nada todavía. Podrá revisar qué obligaciones se
                crearían antes de escribirlas.
            </p>

            <AppAlert v-if="openError" variant="danger" :title="openError" />
        </AppModal>

        <!-- The generation preview: what would be written, and what would not -->
        <AppModal
            :open="previewOpen"
            title="Generar obligaciones"
            :confirm-label="blockedPreview ? 'Cerrar' : 'Generar'"
            :busy="generateBusy"
            :disabled="!blockedPreview && (previewBusy || plan === null)"
            wide
            @confirm="blockedPreview ? (previewOpen = false) : generate()"
            @cancel="previewOpen = false"
        >
            <AppLoading v-if="previewBusy" label="Calculando" />

            <template v-else-if="plan">
                <p class="cdh-form-hint">{{ generationSummary }}</p>

                <!--
                    There was a "Solo las que faltan" checkbox here. It is gone because it
                    could not do anything: generation is unconditionally missing-only, and an
                    obligation is never regenerated. A control that cannot change the outcome
                    is worse than no control, because an operator can reasonably believe they
                    chose to rewrite a month. The sentence below says what actually happens
                    instead.
                -->
                <p class="cdh-form-hint">
                    Se generarán únicamente las obligaciones que faltan. Las que ya existen no
                    se modifican.
                </p>

                <AppAlert
                    v-if="blockedPreview"
                    variant="warning"
                    title="No se puede generar todavía"
                    class="mb-3"
                >
                    <p class="mb-2">
                        Estas relaciones necesitan una configuración que no existe. El sistema
                        no va a suponerla:
                    </p>
                    <ul class="mb-0">
                        <li v-for="(blocker, index) in plan.blockers" :key="index">
                            {{ blocker.message }}
                        </li>
                    </ul>
                </AppAlert>

                <div class="cdh-table-wrap">
                    <table class="cdh-table">
                        <caption class="cdh-visually-hidden">
                            Obligaciones que se crearían
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">Cliente</th>
                                <th scope="col">Empresa</th>
                                <th scope="col">Valor</th>
                                <th scope="col">Vence</th>
                                <th scope="col">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="candidate in plan.candidates"
                                :key="`${candidate.client_id}-${candidate.company_id}`"
                            >
                                <td data-label="Cliente">{{ candidate.client_name ?? '—' }}</td>
                                <td data-label="Empresa">{{ candidate.company_name ?? '—' }}</td>
                                <td data-label="Valor" class="cdh-table__numeric">
                                    {{ candidate.amount_cop === null ? '—' : pesos(candidate.amount_cop) }}
                                </td>
                                <td data-label="Vence">
                                    {{ candidate.cutoff.due_on ?? '—' }}
                                </td>
                                <td data-label="Estado">
                                    <span
                                        v-if="candidate.blockers.length > 0"
                                        class="cdh-badge cdh-badge--warning"
                                    >
                                        bloqueada
                                    </span>
                                    <span
                                        v-else-if="!candidate.will_be_created"
                                        class="cdh-badge cdh-badge--neutral"
                                    >
                                        ya existe
                                    </span>
                                    <span v-else class="cdh-badge cdh-badge--info">nueva</span>
                                </td>
                            </tr>

                            <tr v-if="plan.candidates.length === 0">
                                <td colspan="5" class="cdh-table__secondary">
                                    No hay relaciones vigentes en este mes.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <AppAlert
                    v-if="plan.warnings.length > 0"
                    variant="info"
                    title="Sin cambios en algunas relaciones"
                >
                    <ul class="mb-0">
                        <li v-for="(warning, index) in plan.warnings" :key="index">
                            {{ warning.message }}
                        </li>
                    </ul>
                </AppAlert>
            </template>

            <AppAlert v-if="previewError" variant="danger" :title="previewError" />
        </AppModal>

        <!-- Closing a month -->
        <AppModal
            :open="closeTarget !== null"
            title="Cerrar periodo"
            confirm-label="Cerrar periodo"
            :busy="closeBusy"
            @confirm="closePeriod"
            @cancel="closeTarget = null"
        >
            <p>
                Se cerrará <strong>{{ closeTarget?.label }}</strong>. Un periodo cerrado ya no
                acepta cambios en su estructura.
            </p>
            <p class="cdh-form-hint">
                Cerrar no bloquea el dinero: los pagos y los ajustes de ese mes siguen siendo
                válidos, porque lo que ya se facturó no deja de existir.
            </p>

            <AppAlert v-if="closeError" variant="danger" :title="closeError" />
        </AppModal>

        <!-- Reopening a month: the reason is kept in the audit trail -->
        <AppModal
            :open="reopenTarget !== null"
            title="Reabrir periodo"
            confirm-label="Reabrir periodo"
            :busy="reopenBusy"
            :disabled="reopenReason.trim() === ''"
            @confirm="reopenPeriod"
            @cancel="reopenTarget = null"
        >
            <p>
                <strong>{{ reopenTarget?.label }}</strong> volverá a aceptar cambios en su
                estructura. La obligación ya generada no se recalcula.
            </p>

            <div class="mb-3">
                <label class="cdh-form-label" for="reopen-reason">Motivo</label>
                <textarea
                    id="reopen-reason"
                    v-model="reopenReason"
                    class="form-control"
                    rows="3"
                    required
                ></textarea>
                <p class="cdh-form-hint">Queda en la auditoría. Sin motivo no se reabre.</p>
            </div>

            <AppAlert v-if="reopenError" variant="danger" :title="reopenError" />
        </AppModal>
    </section>
</template>
