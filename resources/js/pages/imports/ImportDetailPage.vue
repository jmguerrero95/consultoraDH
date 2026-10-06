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
    ImportAction,
    ImportIssue,
    ImportIssueDecision,
    ImportPlan as ImportPlanPayload,
    ImportRetirementPolicy,
    ImportRow,
    IssueResolutionDecision,
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

/**
 * §15's "Listados con paginación server-side".
 *
 * The audit's Area U finding: `loadRows()` and `loadIssues()` took the first page and threw the
 * rest away. On the real workbook that is 2.560 rows and a few hundred findings, so the reviewer
 * searching for one client was shown page one of a table they could not reach the end of — and
 * the search ran server-side, so a document on page forty was *invisible* while the screen said
 * it had been filtered.
 *
 * The page and the search term are now part of the request, and `total` is displayed, so a
 * reviewer can see that a filter matched 3 of 2.560 rather than assuming it matched everything.
 */
const rowPage = ref(1);
const issuePage = ref(1);
const rowsMeta = ref({ current_page: 1, last_page: 1, per_page: 50, total: 0 });
const issuesMeta = ref({ current_page: 1, last_page: 1, per_page: 50, total: 0 });

const confirmApply = ref(false);
const resolving = ref<ImportIssue | null>(null);
const resolutionDecision = ref<IssueResolutionDecision | ''>('');
const resolutionValue = ref<Record<string, string>>({});
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
        const payload = await businessApi.imports.rows(importId.value, {
            search: rowSearch.value === '' ? undefined : rowSearch.value,
            page: rowPage.value,
        });

        rows.value = payload.data;
        rowsMeta.value = payload.meta;
    } catch {
        rows.value = [];
    }
}

async function loadIssues(): Promise<void> {
    try {
        const payload = await businessApi.imports.issues(importId.value, {
            code: issueCode.value === '' ? undefined : issueCode.value,
            unresolved: issueUnresolved.value,
            page: issuePage.value,
        });

        issues.value = payload.data;
        issuesMeta.value = payload.meta;
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

/**
 * §5.4's confirmation: the revision and digest this screen is showing.
 *
 * ## Why the identity travels with the click
 *
 * A04-R1. The audit found `apply` accepted no request body, so nothing connected the operator's
 * confirmation to the rows that were written. A resolution in another tab, or the plan job
 * finishing late, meant a different plan was applied — and the screen reported success while
 * reporting a different revision than the one that ran.
 *
 * The digest is captured **when the plan is loaded**, not when Apply is pressed. Capturing it at
 * press time would defeat the check: `loadPlan()` runs on a timer, and reading the current value
 * on click would send the newest revision even if the reviewer had been looking at an older one
 * for ten minutes.
 */
async function applyPlan(): Promise<void> {
    const confirmation = plan.value;

    if (confirmation === null || confirmation.plan_digest === null) {
        error.value = 'No hay un plan que aplicar. Recargue la página.';

        return;
    }

    busy.value = true;
    notice.value = null;
    error.value = null;

    try {
        const payload = await businessApi.imports.apply(importId.value, {
            plan_revision: confirmation.plan_revision,
            plan_digest: confirmation.plan_digest,
        });

        notice.value = payload.message;
        confirmApply.value = false;

        await load();
    } catch (cause) {
        if (cause instanceof ApiError && (STALE_PLAN_CODES as readonly string[]).includes(cause.code ?? '')) {
            // The plan moved. Nothing was written, and the useful next step is to read the new
            // one — so the dialog closes and the plan is reloaded, with the reason stated.
            stalePlan.value = cause.message;
            confirmApply.value = false;

            await loadPlan();

            return;
        }

        error.value = cause instanceof ApiError ? cause.message : 'No fue posible aplicar el plan.';
    } finally {
        busy.value = false;
    }
}

/**
 * §15's 409 codes that all mean the same first step: reload the plan and read it again.
 *
 * Distinct codes, one action. `stale_plan` is the case the audit describes — the content
 * changed. `plan_rebuilt` means it was rebuilt to identical content, so re-confirming is enough.
 * `plan_not_confirmed` means the request arrived without the identity, which this screen never
 * does and which a scripted client would.
 */
const STALE_PLAN_CODES = ['stale_plan', 'plan_rebuilt', 'plan_not_confirmed'] as const;

const stalePlan = ref<string | null>(null);

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
    resolutionValue.value = {};
    resolutionNote.value = '';
}

/**
 * The decisions this issue accepts, from the server.
 *
 * ## Why this list is not written here
 *
 * A04-R1. The previous `resolutionOptions()` was a `switch` in this file listing decision names
 * that **did not exist in the backend** — `accept_absence`, `correct_date`, `keep_first`,
 * `acknowledge`, `recognise_transfer`, `authorise_parallel` — none of which the API would ever
 * accept, while the API accepted any string at all. So the dialog offered answers that failed and
 * hid the ones that worked, and the backend's actual whitelist (`IssueResolutionDecision`) was
 * invisible to the only person who needed it.
 *
 * `allowed_decisions` comes from `IssueResolution::allowedFor()` on the server, so there is one
 * whitelist and it is the one that validates.
 */
function resolutionOptions(issue: ImportIssue): ImportIssueDecision[] {
    return issue.allowed_decisions;
}

const selectedDecision = computed<ImportIssueDecision | null>(() => {
    if (resolving.value === null || resolutionDecision.value === '') {
        return null;
    }

    return resolutionOptions(resolving.value).find((option) => option.value === resolutionDecision.value) ?? null;
});

/**
 * The fields the chosen decision needs, as a list the template can render controls from.
 *
 * `value_schema` is the server's declaration — the same one `IssueResolution` validates against —
 * so a dialog cannot offer a field the API will refuse, and a field the API requires cannot be
 * missing from the form.
 */
const decisionFields = computed<{ key: string; rule: string }[]>(() =>
    Object.entries(selectedDecision.value?.value_schema ?? {}).map(([key, rule]) => ({ key, rule })),
);

/**
 * Whether the form has everything the chosen decision requires.
 *
 * A `requires_value` decision with a missing field is a guaranteed 422, so the button is
 * disabled instead — with the reason being visible rather than the click failing.
 */
const resolutionValueComplete = computed(
    () => decisionFields.value.every((field) => (resolutionValue.value[field.key] ?? '').trim() !== ''),
);

const canSubmitResolution = computed(
    () =>
        resolving.value !== null
        && resolutionDecision.value !== ''
        && (!selectedDecision.value?.requires_value || resolutionValueComplete.value)
        && !busy.value,
);

/** §9.1's four columns. Mirrors `SocialSecurityEntityType`, which is what the value must be. */
const entityTypes = ['EPS', 'AFP', 'ARL', 'CCF'] as const;

/** §9.4's tie-break: which of the two contradicting sources to believe. */
const arlSources = ['title', 'header'] as const;

/** A label per declared rule, so the dialog names the field rather than showing a bare input. */
function fieldLabel(field: { key: string; rule: string }): string {
    const base: Record<string, string> = {
        date: 'Fecha',
        boundary: 'Frontera',
        precision: 'Precisión',
        positive_integer: 'Identificador',
        risk_class: 'Nivel de riesgo (1 a 5)',
        entity_name: 'Nombre de la entidad',
        entity_type: 'Tipo de entidad',
        source_key: 'Fila de referencia',
        tax_id: 'NIT',
        optional_single_char: 'Dígito de verificación',
        optional_text: 'Nombre (opcional)',
        arl_source: 'Fuente del ARL',
    };

    return base[field.rule] ?? field.key;
}

/**
 * Help per rule, where the answer is not obvious from the type.
 *
 * §8.3's precision and §9.4's ARL source are the two that matter: both are cases where picking
 * the wrong value silently changes somebody's history, and a bare dropdown gives no reason to
 * think about it.
 */
function fieldHelp(field: { key: string; rule: string }): string | null {
    const help: Record<string, string> = {
        precision: 'Con «mes» la fecha debe ser el primer día del mes: el archivo afirma el mes, no el día.',
        boundary: 'La fecha donde termina el episodio anterior y empieza el siguiente.',
        arl_source: '§9.4 da prioridad al título de la empresa; elija la columna sólo si el título no la nombra.',
        source_key: 'La fila de la que es duplicado. Búsquela en la pestaña Filas.',
        entity_name: 'Se creará en el catálogo sólo cuando se aplique el plan.',
    };

    return help[field.rule] ?? null;
}

/** The value the API expects for each field's declared type. */
function typedResolutionValue(): Record<string, unknown> | null {
    const decision = selectedDecision.value;

    if (decision === null || !decision.requires_value) {
        return null;
    }

    const value: Record<string, unknown> = {};

    for (const field of decisionFields.value) {
        const raw = (resolutionValue.value[field.key] ?? '').trim();

        switch (field.rule) {
            case 'positive_integer':
            case 'risk_class':
                value[field.key] = Number.parseInt(raw, 10);
                break;
            case 'optional_text':
            case 'optional_single_char':
                // Null rather than `''`: the schemas distinguish "not supplied" from "supplied
                // empty", and `IssueResolution::asOptionalText()` treats `''` as absent anyway.
                value[field.key] = raw === '' ? null : raw;
                break;
            default:
                value[field.key] = raw;
        }
    }

    return value;
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
            value: typedResolutionValue(),
            note: resolutionNote.value === '' ? null : resolutionNote.value,
        });

        notice.value = payload.message;

        // §5.5: an approved entity mapping is reusable, and saying so is the difference between a
        // reviewer trusting that the answer generalises and guessing whether it does.
        if (payload.data.reusable_mapping !== null) {
            notice.value = `${payload.message} «${payload.data.reusable_mapping.source_key}» queda asociado a esa entidad para las próximas importaciones.`;
        }

        resolving.value = null;

        await load();
    } catch (cause) {
        error.value = cause instanceof ApiError ? cause.message : 'No fue posible resolver la incidencia.';
    } finally {
        busy.value = false;
    }
}

// A new search or filter starts at page one. Paging forward from the previous page's cursor is
// how a reviewer ends up on an empty page and concludes the filter found nothing.
watch(rowSearch, () => {
    rowPage.value = 1;
    void loadRows();
});
watch([issueCode, issueUnresolved], () => {
    issuePage.value = 1;
    void loadIssues();
});
watch(rowPage, () => void loadRows());
watch(issuePage, () => void loadIssues());
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
 * How a finding stands, in one word. A04-R2.
 *
 * Three states, because two of them used to look alike: answered by a person, withdrawn because
 * the reconstruction stopped producing it, and still open. The middle one is the reason
 * `superseded_at` is a separate column rather than a value written into `resolved_at`.
 */
function answerState(issue: ImportIssue): string {
    if (issue.is_resolved) {
        return 'Respondida';
    }

    if (issue.is_superseded) {
        return 'Ya no aplica';
    }

    return 'Abierta';
}

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

/**
 * §12.3's three outcomes plus `planned`.
 *
 * A04-R1 added `failed`, and it has to be visibly different from `planned` and `applied`: the
 * audit's finding was that an action which could not be executed was left `planned` and counted
 * as applied, so an operator reading the plan after the fact had no way to tell which writes
 * actually happened. The three states now look like three different things.
 */
function actionStateClass(action: ImportAction): string {
    switch (action.state) {
        case 'applied':
            return 'cdh-badge--success';
        case 'skipped':
            return 'cdh-badge--info';
        case 'failed':
            return 'cdh-badge--error';
        default:
            return 'cdh-badge--warning';
    }
}

function severityClass(issue: ImportIssue): string {
    if (issue.blocking) {
        return 'cdh-badge--error';
    }

    return issue.severity === 'warning' ? 'cdh-badge--warning' : 'cdh-badge--info';
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

                    <!--
                        §15: "Listados con paginación server-side".

                        The count matters as much as the buttons. A reviewer who searched for one
                        document needs to see that it matched 1 of 2.560, because a filtered
                        first page that happens to be empty is indistinguishable from "not found" —
                        and the previous version took page one and discarded the rest, so on the
                        real workbook a document on page forty was invisible while the screen
                        claimed it had been filtered.
                    -->
                    <nav v-if="rowsMeta.last_page > 1" class="cdh-pagination" aria-label="Paginación de filas">
                        <button
                            type="button"
                            class="cdh-link"
                            :disabled="rowsMeta.current_page <= 1"
                            @click="rowPage = rowsMeta.current_page - 1"
                        >
                            Anterior
                        </button>
                        <span class="cdh-pagination__label">
                            Página {{ rowsMeta.current_page }} de {{ rowsMeta.last_page }} · {{ rowsMeta.total }} filas
                        </span>
                        <button
                            type="button"
                            class="cdh-link"
                            :disabled="rowsMeta.current_page >= rowsMeta.last_page"
                            @click="rowPage = rowsMeta.current_page + 1"
                        >
                            Siguiente
                        </button>
                    </nav>
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
                                <td>
                                    {{ issue.message }}
                                    <!--
                                        §5.3's record of what was decided. The previous cell said
                                        only "Sí", so a reviewer opening the batch later could
                                        not tell what the earlier answer actually was — and the
                                        audit found the answer was stored but never read by
                                        anything at all.
                                    -->
                                    <span v-if="issue.resolution_summary" class="cdh-text-muted d-block">
                                        {{ issue.resolution_summary }}
                                    </span>
                                    <!--
                                        A04-R2. A withdrawn finding is neither answered nor open,
                                        and the screen has to say which: "Sí" would put a
                                        reviewer who never saw this dialog into the record.
                                    -->
                                    <span v-if="issue.is_superseded" class="cdh-text-muted d-block">
                                        Ya no aplica: la reconstrucción cambió.
                                    </span>
                                </td>
                                <td>{{ answerState(issue) }}</td>
                                <td>
                                    <!--
                                        A04-R2: no "Resolver" on a finding that stopped applying.
                                        The endpoint refuses it now as well; disabling the control
                                        is what keeps the screen and the server agreeing.
                                    -->
                                    <button
                                        v-if="canReview && issue.resolved_at === null && !issue.is_superseded"
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

                    <nav v-if="issuesMeta.last_page > 1" class="cdh-pagination" aria-label="Paginación de incidencias">
                        <button
                            type="button"
                            class="cdh-link"
                            :disabled="issuesMeta.current_page <= 1"
                            @click="issuePage = issuesMeta.current_page - 1"
                        >
                            Anterior
                        </button>
                        <span class="cdh-pagination__label">
                            Página {{ issuesMeta.current_page }} de {{ issuesMeta.last_page }} ·
                            {{ issuesMeta.total }} incidencias
                        </span>
                        <button
                            type="button"
                            class="cdh-link"
                            :disabled="issuesMeta.current_page >= issuesMeta.last_page"
                            @click="issuePage = issuesMeta.current_page + 1"
                        >
                            Siguiente
                        </button>
                    </nav>
            </section>

            <!-- ---------------------------------------------------------- Plan -->
            <section v-else role="tabpanel" aria-label="Plan">
                <div v-if="plan === null" class="cdh-empty">
                    <p class="cdh-text-muted">Todavía no hay un plan para esta importación.</p>
                </div>

                <template v-else>
                    <!--
                        §5.4's identity. Shown because the reviewer is about to approve *this*
                        revision, and the number is what appears in the audit trail if something
                        goes wrong afterwards. The digest is truncated for display and sent whole.
                    -->
                    <AppAlert
                        v-if="stalePlan !== null"
                        variant="warning"
                        title="El plan cambió"
                        class="mb-3"
                    >
                        {{ stalePlan }}
                    </AppAlert>

                    <p class="cdh-text-muted mb-3">
                        Revisión del plan <strong>{{ plan.plan_revision }}</strong>
                        <span v-if="plan.plan_built_at">
                            · generado el {{ new Date(plan.plan_built_at).toLocaleString('es-CO') }}
                        </span>
                        <span v-if="plan.plan_digest !== null">
                            · huella <code>{{ plan.plan_digest.slice(0, 12) }}</code>
                        </span>
                    </p>

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
                                        <span class="cdh-badge" :class="actionStateClass(action)">
                                            {{ action.state }}
                                        </span>
                                        <span v-if="action.skip_reason" class="cdh-text-muted d-block">
                                            {{ action.skip_reason }}
                                        </span>
                                        <!--
                                            A04-R1. `failed` is new, and it is the state the
                                            audit found unreachable: an action whose payload
                                            could not be written was skipped silently, counted as
                                            a success and left `planned`, while the batch still
                                            reported `applied`. §12.3's reason has to be on
                                            screen.
                                        -->
                                        <span v-if="action.failure_message" class="cdh-form-hint d-block">
                                            {{ action.failure_message }}
                                        </span>
                                    </td>
                                    <!--
                                        §13 and §17.5: which lines of the file produced this
                                        write, and the *ids* so the claim is verifiable rather
                                        than a row number that collides across the ten sheets.
                                    -->
                                    <td>
                                        <span v-if="action.source_evidence !== null && action.source_evidence.cells.length > 0">
                                            {{ action.source_evidence.cells.join(' · ') }}
                                        </span>
                                        <span v-else class="cdh-text-muted">—</span>
                                        <span v-if="action.source_row_ids.length > 0" class="cdh-text-muted d-block">
                                            filas {{ action.source_row_ids.join(', ') }}
                                        </span>
                                    </td>
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
                :disabled="!canSubmitResolution"
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
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}{{ option.resolves ? '' : ' (no quita el bloqueo)' }}
                        </option>
                    </select>
                </div>

                <!--
                    The fields come from the chosen decision's `value_schema`, which is the
                    server's own declaration. A04-R1: the previous dialog had no value inputs at
                    all — it sent `{decision, note}` and nothing else — so every decision that
                    needs a value (a date, an entity id, a NIT) could not be expressed. The
                    server refused those payloads, which is the right answer, and the screen had
                    no way to give it one.
                -->
                <div v-if="decisionFields.length > 0" class="cdh-form-field mb-3">
                    <div v-for="field in decisionFields" :key="field.key" class="mb-2">
                        <label class="cdh-form-label" :for="`resolution-${field.key}`">
                            {{ fieldLabel(field) }}
                        </label>
                        <select
                            v-if="field.rule === 'entity_type' || field.rule === 'arl_source'"
                            :id="`resolution-${field.key}`"
                            v-model="resolutionValue[field.key]"
                            class="form-control form-control-sm"
                        >
                            <option value="">Elija…</option>
                            <option v-for="option in field.rule === 'entity_type' ? entityTypes : arlSources" :key="option" :value="option">
                                {{ option }}
                            </option>
                        </select>
                        <input
                            v-else
                            :id="`resolution-${field.key}`"
                            v-model="resolutionValue[field.key]"
                            class="form-control form-control-sm"
                            :type="field.rule === 'date' ? 'date' : 'text'"
                            :inputmode="field.rule === 'positive_integer' || field.rule === 'risk_class' ? 'numeric' : undefined"
                            :min="field.rule === 'risk_class' ? '1' : undefined"
                            :max="field.rule === 'risk_class' ? '5' : undefined"
                        />
                        <p v-if="fieldHelp(field)" class="cdh-form-hint">{{ fieldHelp(field) }}</p>
                    </div>
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
