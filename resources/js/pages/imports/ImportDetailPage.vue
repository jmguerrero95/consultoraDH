<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import AppModal from '@/components/ui/AppModal.vue';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';

import type {
    ImportIssue,
    ImportPlan as ImportPlanPayload,
    ImportRetirementPolicy,
    ImportRow,
    LegacyImportDetail,
} from '@/types/api';

/**
 * The import review screen (§17.3–§17.5).
 *
 * ## The stepper is the status, spelled out
 *
 * `Archivo → Análisis → Revisión → Plan → Aplicado`, and each step is either done, current
 * or not reached. A step is never shown as "done" because a request returned, and never
 * hidden because it is inconvenient: a reviewer deciding whether to apply a plan is deciding
 * about somebody's history.
 *
 * ## Apply is disabled while a blocker is open, and the reason is shown
 *
 * The server refuses it too — §14's "backend manda". Here the button is disabled and the
 * blocking count is stated next to it, because a button that is simply greyed out with no
 * explanation is the most common way an interface loses a reviewer's trust.
 *
 * ## The preview reads the plan, never recomputes it
 *
 * §5.4 and §17.5: the actions listed here are the rows `ApplyLegacyImport` will execute. The
 * counts on this screen are therefore a promise, not an estimate.
 */

const route = useRoute();
const router = useRouter();

const auth = useAuthStore();
const canReview = computed(() => auth.can('imports.review'));
const canApply = computed(() => auth.can('imports.apply'));

const STEPS = [
    { key: 'uploaded', label: 'Archivo' },
    { key: 'parsing', label: 'Análisis' },
    { key: 'review', label: 'Revisión' },
    { key: 'ready', label: 'Plan' },
    { key: 'applied', label: 'Aplicado' },
] as const;

type Tab = 'resumen' | 'filas' | 'incidencias' | 'plan';

const TABS: { key: Tab; label: string }[] = [
    { key: 'resumen', label: 'Resumen' },
    { key: 'filas', label: 'Filas' },
    { key: 'incidencias', label: 'Incidencias' },
    { key: 'plan', label: 'Plan' },
];

const importId = computed(() => Number(route.params.id));

const detail = ref<LegacyImportDetail | null>(null);
const rows = ref<ImportRow[]>([]);
const issues = ref<ImportIssue[]>([]);
const plan = ref<ImportPlanPayload | null>(null);

const loading = ref(true);
const error = ref<string | null>(null);
const notice = ref<string | null>(null);
const busy = ref(false);

const tab = ref<Tab>('resumen');
const rowSearch = ref('');
const issueCode = ref('');
const issueUnresolved = ref(true);

const confirmApply = ref(false);
const resolving = ref<ImportIssue | null>(null);
const resolutionDecision = ref('');
const resolutionNote = ref('');

let requestId = 0;
let pollTimer: ReturnType<typeof setTimeout> | null = null;

async function load(): Promise<void> {
    const current = ++requestId;

    loading.value = detail.value === null;
    error.value = null;

    try {
        const payload = await businessApi.imports.show(importId.value);

        if (current !== requestId) {
            return;
        }

        detail.value = payload.data;

        await Promise.all([loadRows(), loadIssues(), loadPlan()]);
    } catch (cause) {
        if (current !== requestId) {
            return;
        }

        error.value = cause instanceof ApiError ? cause.message : 'No fue posible cargar la importación.';
    } finally {
        if (current === requestId) {
            loading.value = false;
        }
    }
}

async function loadRows(): Promise<void> {
    try {
        rows.value = (await businessApi.imports.rows(importId.value, { search: rowSearch.value })).data;
    } catch {
        rows.value = [];
    }
}

async function loadIssues(): Promise<void> {
    try {
        issues.value = (
            await businessApi.imports.issues(importId.value, {
                code: issueCode.value,
                unresolved: issueUnresolved.value,
            })
        ).data;
    } catch {
        issues.value = [];
    }
}

async function loadPlan(): Promise<void> {
    try {
        plan.value = (await businessApi.imports.plan(importId.value)).data;
    } catch {
        plan.value = null;
    }
}

function isPending(): boolean {
    return detail.value !== null && ['uploaded', 'queued', 'parsing', 'applying'].includes(detail.value.status);
}

function schedulePoll(): void {
    if (pollTimer !== null) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }

    if (!isPending()) {
        return;
    }

    pollTimer = setTimeout(() => void load(), 3000);
}

async function applyPlan(): Promise<void> {
    busy.value = true;
    notice.value = null;
    error.value = null;

    try {
        const payload = await businessApi.imports.apply(importId.value);

        notice.value = payload.message;
        confirmApply.value = false;

        await load();
    } catch (cause) {
        error.value = cause instanceof ApiError ? cause.message : 'No fue posible aplicar el plan.';
    } finally {
        busy.value = false;
    }
}

async function setPolicy(policy: ImportRetirementPolicy): Promise<void> {
    busy.value = true;

    try {
        const payload = await businessApi.imports.setRetirementPolicy(importId.value, policy);

        notice.value = payload.message;

        await load();
    } catch (cause) {
        error.value = cause instanceof ApiError ? cause.message : 'No fue posible guardar la política.';
    } finally {
        busy.value = false;
    }
}

async function rebuildPlan(): Promise<void> {
    busy.value = true;

    try {
        const payload = await businessApi.imports.rebuildPlan(importId.value);

        notice.value = payload.message;

        await load();
    } catch (cause) {
        error.value = cause instanceof ApiError ? cause.message : 'No fue posible reconstruir el plan.';
    } finally {
        busy.value = false;
    }
}

function openResolution(issue: ImportIssue): void {
    resolving.value = issue;
    resolutionDecision.value = '';
    resolutionNote.value = '';
}

async function submitResolution(): Promise<void> {
    const issue = resolving.value;

    if (issue === null || resolutionDecision.value === '') {
        return;
    }

    busy.value = true;

    try {
        const payload = await businessApi.imports.resolveIssue(importId.value, issue.id, {
            decision: resolutionDecision.value,
            note: resolutionNote.value === '' ? null : resolutionNote.value,
        });

        notice.value = payload.message;
        resolving.value = null;

        await load();
    } catch (cause) {
        error.value = cause instanceof ApiError ? cause.message : 'No fue posible resolver la incidencia.';
    } finally {
        busy.value = false;
    }
}

watch([rowSearch], () => void loadRows());
watch([issueCode, issueUnresolved], () => void loadIssues());
watch(tab, (value) => {
    if (value === 'filas' && rows.value.length === 0) {
        void loadRows();
    }

    if (value === 'plan' && plan.value === null) {
        void loadPlan();
    }
});
watch(detail, schedulePoll);

onMounted(load);

/**
 * Where the import sits in the stepper.
 *
 * `failed` and `cancelled` are terminal and are not a step, so they report the last step that
 * was actually reached rather than pretending to be one of the five.
 */
const stepIndex = computed(() => {
    const status = detail.value?.status;

    if (status === undefined) {
        return 0;
    }

    if (status === 'failed' || status === 'cancelled') {
        const appliedAt = detail.value?.applied_at;

        return appliedAt === null ? 2 : 4;
    }

    const reached: Record<string, number> = {
        uploaded: 0,
        queued: 0,
        parsing: 1,
        review: 2,
        ready: 3,
        applying: 3,
        applied: 4,
    };

    return reached[status] ?? 0;
});

const blockers = computed(() => detail.value?.issues.blocking ?? 0);
const canApplyNow = computed(
    () => canApply.value && detail.value?.applicable === true && !busy.value,
);

const summary = computed(() => detail.value?.summary ?? {});

/**
 * §8.3's three forms, verbatim.
 *
 * The date is never formatted by this screen on its own: a boundary the source stated as a
 * month has to read as a month, and the only reliable way to do that is for the precision to
 * travel with the value rather than being inferred from the day number.
 */
function dateLabel(value: string | null, precision: string | null): string {
    if (value === null || precision === 'unknown') {
        return 'Fecha desconocida';
    }

    if (precision === 'month') {
        const parsed = new Date(`${value}T00:00:00`);

        if (Number.isNaN(parsed.getTime())) {
            return 'Mes aproximado';
        }

        const month = parsed.toLocaleDateString('es-CO', { month: 'long', year: 'numeric' });

        return `${month.charAt(0).toUpperCase()}${month.slice(1)} (mes aproximado)`;
    }

    const parsed = new Date(`${value}T00:00:00`);

    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleDateString('es-CO');
}

function amount(value: number | null): string {
    if (value === null) {
        return '—';
    }

    return `$${value.toLocaleString('es-CO')}`;
}

function severityClass(issue: ImportIssue): string {
    if (issue.blocking) {
        return 'cdh-badge--error';
    }

    return issue.severity === 'warning' ? 'cdh-badge--warning' : 'cdh-badge--info';
}

function resolutionOptions(issue: ImportIssue): string[] {
    // Each code gets the answers §17.4 lists for it, and nothing else. A free-text decision
    // would let a resolution mean anything, which is how a blocker becomes a silent override.
    switch (issue.code) {
        case 'invalid_affiliation_date':
            return ['accept_absence', 'use_suggested_date', 'correct_date'];
        case 'invalid_company_tax_id':
        case 'company_identity_conflict':
            return ['correct_tax_id', 'keep_separate_companies'];
        case 'duplicate_conflicting_row':
            return ['keep_first', 'keep_last', 'keep_both'];
        case 'relationship_disappeared_without_retirement':
            return ['close_on_last_seen', 'leave_open', 'correct_date'];
        case 'overlapping_company_history':
            return ['recognise_transfer', 'authorise_parallel', 'correct_date'];
        case 'affiliation_entity_unknown':
            return ['ignore_cell', 'map_entity'];
        case 'existing_rate_conflict':
        case 'existing_client_conflict':
        case 'existing_company_conflict':
        case 'existing_relationship_conflict':
        case 'existing_affiliation_conflict':
            return ['keep_existing', 'use_source'];
        default:
            return ['acknowledge'];
    }
}
</script>

<template>
    <section class="cdh-content">
        <AppLoading v-if="loading && detail === null" label="Cargando la importación" />

        <AppAlert v-else-if="error && detail === null" variant="danger" :title="error" class="mb-4" />

        <template v-else-if="detail !== null">
            <header class="cdh-page-header">
                <div>
                    <h1 class="cdh-page-header__title">{{ detail.original_filename }}</h1>
                    <p class="cdh-page-header__subtitle">
                        {{ detail.status_label }} · {{ detail.rows }} filas leídas
                    </p>
                </div>

                <div class="cdh-button-row">
                    <AppButton variant="secondary" icon="bi-arrow-left" @click="router.push({ name: 'imports' })">
                        Volver
                    </AppButton>
                </div>
            </header>

            <!-- §17.3's stepper. Each step is done, current or not reached. -->
            <ol class="cdh-stepper mb-4" aria-label="Estado de la importación">
                <li
                    v-for="(step, index) in STEPS"
                    :key="step.key"
                    class="cdh-stepper__step"
                    :class="{
                        'cdh-stepper__step--done': index < stepIndex,
                        'cdh-stepper__step--current': index === stepIndex,
                    }"
                    :aria-current="index === stepIndex ? 'step' : undefined"
                >
                    {{ step.label }}
                </li>
            </ol>

            <AppAlert v-if="error" variant="danger" :title="error" class="mb-3" />
            <AppAlert v-if="notice" variant="success" :title="notice" class="mb-3" />

            <!-- §17.2 / §12: a refused or failed import says so, in words. -->
            <AppAlert
                v-if="detail.failure_message !== null"
                variant="warning"
                :title="detail.failure_message"
                class="mb-4"
            />

            <div class="cdh-tabs mb-4" role="tablist" aria-label="Secciones de la importación">
                <button
                    v-for="item in TABS"
                    :key="item.key"
                    type="button"
                    role="tab"
                    class="cdh-tab"
                    :class="{ 'cdh-tab--active': tab === item.key }"
                    :aria-selected="tab === item.key"
                    @click="tab = item.key"
                >
                    {{ item.label }}
                    <span
                        v-if="item.key === 'incidencias' && detail.issues.total > 0"
                        class="cdh-badge cdh-badge--error"
                    >
                        {{ detail.issues.total }}
                    </span>
                </button>
            </div>

            <!-- ------------------------------------------------------- Resumen -->
            <section v-if="tab === 'resumen'" role="tabpanel" aria-label="Resumen">
                <div class="cdh-stat-grid mb-4">
                    <div class="cdh-stat">
                        <span class="cdh-stat__value">{{ summary.monthly_sheets ?? '—' }}</span>
                        <span class="cdh-stat__label">Hojas mensuales</span>
                    </div>
                    <div class="cdh-stat">
                        <span class="cdh-stat__value">{{ summary.blocks ?? '—' }}</span>
                        <span class="cdh-stat__label">Bloques de empresa</span>
                    </div>
                    <div class="cdh-stat">
                        <span class="cdh-stat__value">{{ summary.rows ?? '—' }}</span>
                        <span class="cdh-stat__label">Filas</span>
                    </div>
                    <div class="cdh-stat">
                        <span class="cdh-stat__value">{{ summary.document_identities ?? '—' }}</span>
                        <span class="cdh-stat__label">Clientes detectados</span>
                    </div>
                    <div class="cdh-stat">
                        <span class="cdh-stat__value">{{ summary.company_names ?? '—' }}</span>
                        <span class="cdh-stat__label">Empresas detectadas</span>
                    </div>
                    <div class="cdh-stat">
                        <span class="cdh-stat__value">{{ summary.blocking_issues ?? 0 }}</span>
                        <span class="cdh-stat__label">Bloqueos</span>
                    </div>
                    <div class="cdh-stat">
                        <span class="cdh-stat__value">{{ summary.warning_issues ?? 0 }}</span>
                        <span class="cdh-stat__label">Advertencias</span>
                    </div>
                    <div class="cdh-stat">
                        <span class="cdh-stat__value">{{ summary.credential_like_cells ?? 0 }}</span>
                        <span class="cdh-stat__label">Celdas con contraseñas</span>
                    </div>
                </div>

                <!--
                    §8.2's batch rule. It is a decision about this import, so it lives on the
                    screen where the decision is made rather than in a settings page. The safe
                    option is the default and is stated first.
                -->
                <section v-if="canReview" class="cdh-card mb-4" aria-labelledby="policy-title">
                    <div class="cdh-card__body">
                        <h2 id="policy-title" class="cdh-card__title">Interpretación de los retiros</h2>
                        <p class="cdh-text-muted mb-3">
                            Las notas de retiro dicen «15 días», que no es una fecha. Elija qué hacer con
                            ellas antes de generar el plan.
                        </p>

                        <div class="cdh-form-field mb-3">
                            <label class="cdh-form-label" for="retirement-policy">Regla para este archivo</label>
                            <select
                                id="retirement-policy"
                                class="form-control form-control-sm"
                                :value="summary.retirement_policy ?? 'manual_only'"
                                :disabled="busy"
                                @change="setPolicy(($event.target as HTMLSelectElement).value as ImportRetirementPolicy)"
                            >
                                <option value="manual_only">
                                    Sólo registrar el retiro, sin derivar fecha (recomendado)
                                </option>
                                <option value="month_end_boundary">
                                    Cerrar el primer día del mes siguiente al retiro
                                </option>
                            </select>
                        </div>

                        <AppButton variant="secondary" :disabled="busy" @click="rebuildPlan">
                            Regenerar el plan
                        </AppButton>
                    </div>
                </section>

                <section class="cdh-card">
                    <div class="cdh-card__body">
                        <h2 class="cdh-card__title">Aplicar</h2>
                        <p class="cdh-text-muted">
                            <template v-if="blockers > 0">
                                Faltan <strong>{{ blockers }}</strong>
                                {{ blockers === 1 ? 'incidencia bloqueante' : 'incidencias bloqueantes' }} por
                                resolver. No se puede aplicar hasta resolverlas.
                            </template>
                            <template v-else-if="!canApply">
                                Su rol puede revisar pero no aplicar.
                            </template>
                            <template v-else-if="detail.status !== 'ready'">
                                El plan se genera cuando termine la revisión.
                            </template>
                            <template v-else>
                                El plan se aplicará en una sola transacción. Si algo falla, no queda nada
                                escrito a medias.
                            </template>
                        </p>

                        <AppButton :disabled="!canApplyNow" @click="confirmApply = true">
                            Aplicar plan
                        </AppButton>
                    </div>
                </section>
            </section>

            <!-- --------------------------------------------------------- Filas -->
            <section v-else-if="tab === 'filas'" role="tabpanel" aria-label="Filas">
                <div class="cdh-filters" role="search">
                    <div class="cdh-filters__field cdh-filters__field--grow">
                        <label class="cdh-form-label" for="rows-search">Buscar</label>
                        <input
                            id="rows-search"
                            v-model="rowSearch"
                            class="form-control form-control-sm"
                            type="search"
                            placeholder="Documento, nombre o empresa"
                            autocomplete="off"
                        />
                    </div>
                </div>

                <div class="cdh-table-wrap">
                    <table class="cdh-table">
                        <caption class="cdh-visually-hidden">Filas del archivo, ya redactadas</caption>
                        <thead>
                            <tr>
                                <th scope="col">Hoja</th>
                                <th scope="col">Fila</th>
                                <th scope="col">Documento</th>
                                <th scope="col">Nombre</th>
                                <th scope="col" class="cdh-table__wide">Empresa</th>
                                <th scope="col">Fecha de afiliación</th>
                                <th scope="col">Valor</th>
                                <th scope="col">Riesgo</th>
                                <!--
                                    §4.3: this is the redacted text, never the cell as it was
                                    typed. A reviewer resolving a retirement note has to be
                                    able to read what the file actually said.
                                -->
                                <th scope="col" class="cdh-table__wide">Novedad</th>
                                <th scope="col">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.id">
                                <td>{{ row.sheet_name }}</td>
                                <td>{{ row.source_row_number }}</td>
                                <td>{{ row.document_number ?? '—' }}</td>
                                <td>{{ [row.first_names, row.last_names].filter(Boolean).join(' ') || '—' }}</td>
                                <td>{{ row.company_display_name ?? '—' }}</td>
                                <!--
                                    §8.3: the precision travels with the date, so a month the
                                    source stated as a month is never shown as a day.
                                -->
                                <td>
                                    {{ dateLabel(row.affiliation_date, row.affiliation_date_precision) }}
                                    <span
                                        v-if="row.affiliation_date_raw"
                                        class="cdh-text-muted d-block"
                                    >
                                        (en el archivo: {{ row.affiliation_date_raw }})
                                    </span>
                                </td>
                                <td>{{ amount(row.monthly_amount_cop) }}</td>
                                <td>{{ row.arl_risk_class ?? '—' }}</td>
                                <td>{{ row.novelty ?? '—' }}</td>
                                <td>
                                    <span
                                        class="cdh-badge"
                                        :class="row.parse_state === 'blocked' ? 'cdh-badge--error' : 'cdh-badge--info'"
                                    >
                                        {{ row.parse_state ?? '—' }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ---------------------------------------------------- Incidencias -->
            <section v-else-if="tab === 'incidencias'" role="tabpanel" aria-label="Incidencias">
                <div class="cdh-filters" role="search">
                    <div class="cdh-filters__field cdh-filters__field--grow">
                        <label class="cdh-form-label" for="issues-code">Código</label>
                        <input
                            id="issues-code"
                            v-model="issueCode"
                            class="form-control form-control-sm"
                            type="search"
                            placeholder="invalid_affiliation_date"
                            autocomplete="off"
                        />
                    </div>

                    <div class="cdh-filters__field">
                        <label class="cdh-form-check" for="issues-unresolved">
                            <input id="issues-unresolved" v-model="issueUnresolved" type="checkbox" />
                            <span>Sólo sin resolver</span>
                        </label>
                    </div>
                </div>

                <div v-if="issues.length === 0" class="cdh-empty">
                    <p class="cdh-text-muted">No hay incidencias que coincidan.</p>
                </div>

                <div v-else class="cdh-table-wrap">
                    <table class="cdh-table">
                        <caption class="cdh-visually-hidden">Incidencias por resolver</caption>
                        <thead>
                            <tr>
                                <th scope="col">Código</th>
                                <th scope="col">Gravedad</th>
                                <th scope="col" class="cdh-table__wide">Qué ocurre</th>
                                <th scope="col">Resuelta</th>
                                <th scope="col"><span class="cdh-visually-hidden">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="issue in issues" :key="issue.id">
                                <th scope="row" class="cdh-table__primary">{{ issue.code }}</th>
                                <td><span class="cdh-badge" :class="severityClass(issue)">{{ issue.severity }}</span></td>
                                <td>{{ issue.message }}</td>
                                <td>{{ issue.resolved_at ? 'Sí' : 'No' }}</td>
                                <td>
                                    <button
                                        v-if="canReview && issue.resolved_at === null"
                                        type="button"
                                        class="cdh-link"
                                        @click="openResolution(issue)"
                                    >
                                        Resolver
                                    </button>
                                    <span v-else class="cdh-text-muted">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ---------------------------------------------------------- Plan -->
            <section v-else role="tabpanel" aria-label="Plan">
                <div v-if="plan === null" class="cdh-empty">
                    <p class="cdh-text-muted">Todavía no hay un plan para esta importación.</p>
                </div>

                <template v-else>
                    <!-- §17.5's four buckets, with counts. -->
                    <div class="cdh-stat-grid mb-4">
                        <div class="cdh-stat">
                            <span class="cdh-stat__value">{{ plan.counts.create }}</span>
                            <span class="cdh-stat__label">Crear</span>
                        </div>
                        <div class="cdh-stat">
                            <span class="cdh-stat__value">{{ plan.counts.update }}</span>
                            <span class="cdh-stat__label">Actualizar</span>
                        </div>
                        <div class="cdh-stat">
                            <span class="cdh-stat__value">{{ plan.counts.unchanged }}</span>
                            <span class="cdh-stat__label">Sin cambios</span>
                        </div>
                        <div class="cdh-stat">
                            <span class="cdh-stat__value">{{ plan.counts.blocked }}</span>
                            <span class="cdh-stat__label">Bloqueados</span>
                        </div>
                    </div>

                    <div class="cdh-table-wrap">
                        <table class="cdh-table">
                            <caption class="cdh-visually-hidden">
                                Acciones exactas que se aplicarán
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">Acción</th>
                                    <th scope="col" class="cdh-table__wide">Identificador</th>
                                    <th scope="col">Estado</th>
                                    <th scope="col">Evidencia</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="action in plan.actions" :key="action.id">
                                    <th scope="row" class="cdh-table__primary">{{ action.action_type }}</th>
                                    <td>{{ action.natural_key }}</td>
                                    <td>
                                        <span
                                            class="cdh-badge"
                                            :class="action.state === 'applied' ? 'cdh-badge--success' : 'cdh-badge--info'"
                                        >
                                            {{ action.state }}
                                        </span>
                                        <span v-if="action.skip_reason" class="cdh-text-muted d-block">
                                            {{ action.skip_reason }}
                                        </span>
                                    </td>
                                    <!-- §13: which rows of the file produced this write. -->
                                    <td>{{ action.source_row_ids.join(', ') || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </section>

            <!--
                §17.5: an explicit confirmation, and not the closing of a modal. The button
                below is the only way in, and it says what will happen.
            -->
            <AppModal
                :open="confirmApply"
                title="Aplicar el plan"
                confirm-label="Sí, aplicar"
                cancel-label="No, volver"
                :busy="busy"
                :disabled="blockers > 0"
                @confirm="applyPlan"
                @cancel="confirmApply = false"
            >
                <p>
                    Se escribirán <strong>{{ plan?.counts.create ?? 0 }}</strong> registros nuevos en
                    clientes, empresas, relaciones, afiliaciones y valores. La operación es de todo o
                    nada: si algo falla, no queda nada escrito.
                </p>
                <p v-if="blockers > 0" class="cdh-text-danger">
                    Quedan {{ blockers }} incidencias bloqueantes sin resolver. El servidor rechazará
                    la operación.
                </p>
            </AppModal>

            <!-- §17.4: a decision is one of the options this issue offers. -->
            <AppModal
                :open="resolving !== null"
                :title="resolving === null ? 'Resolver' : `Resolver ${resolving.code}`"
                confirm-label="Guardar decisión"
                cancel-label="Cancelar"
                :busy="busy"
                :disabled="resolutionDecision === ''"
                @confirm="submitResolution"
                @cancel="resolving = null"
            >
                <p v-if="resolving !== null" class="mb-3">{{ resolving.message }}</p>

                <div class="cdh-form-field mb-3">
                    <label class="cdh-form-label" for="resolution-decision">Qué decide</label>
                    <select
                        id="resolution-decision"
                        v-model="resolutionDecision"
                        class="form-control form-control-sm"
                    >
                        <option value="">Elija una opción</option>
                        <option
                            v-for="option in resolving === null ? [] : resolutionOptions(resolving)"
                            :key="option"
                            :value="option"
                        >
                            {{ option }}
                        </option>
                    </select>
                </div>

                <div class="cdh-form-field">
                    <label class="cdh-form-label" for="resolution-note">Nota (opcional)</label>
                    <input
                        id="resolution-note"
                        v-model="resolutionNote"
                        class="form-control form-control-sm"
                        type="text"
                        maxlength="500"
                    />
                </div>
            </AppModal>
        </template>
    </section>
</template>
