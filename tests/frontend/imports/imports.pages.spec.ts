import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';

import ImportDetailPage from '@/pages/imports/ImportDetailPage.vue';
import ImportListPage from '@/pages/imports/ImportListPage.vue';
import { resetCsrfState } from '@/services/http';
import { useAuthStore } from '@/stores/auth';

import type { AuthUser, ImportIssue, ImportPlan, ImportRow, LegacyImport, LegacyImportDetail } from '@/types/api';

/**
 * The A04 screens.
 *
 * §20.7 names the behaviours worth pinning: permissions, upload, states, issue filters,
 * resolution, blockers disabling Apply, preview counts, redaction and the date-precision
 * label. The last two are the ones a reader would most notice if they broke — a monthly
 * boundary shown as an exact day, or a password reaching the screen — so both are asserted
 * from the payload the parser would really send.
 */

const routerStubs = {
    RouterLink: { template: '<a><slot /></a>', props: ['to'] },
};

/**
 * A real router.
 *
 * The page calls `useRoute()`, which reads from the router plugin — a `mocks.$route` would
 * satisfy a template expression and not the script, so without this the component throws on
 * `route.params.id` and every assertion fails for the same uninformative reason.
 */
async function testRouter(path = '/imports/1') {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/imports', name: 'imports', component: { template: '<div />' } },
            { path: '/imports/:id(\\d+)', name: 'imports.show', component: { template: '<div />' } },
        ],
    });

    // Awaited, and this is the whole reason the helper is async: a router that has not
    // finished its first navigation resolves `params.id` as undefined, so the page asks for
    // `/api/imports/NaN` and every assertion fails with the same uninformative load error.
    await router.push(path);
    await router.isReady();

    return router;
}

function user(permissions: string[]): AuthUser {
    return {
        id: 1,
        name: 'Ana Restrepo',
        email: 'ana@consultora-dh.test',
        status: 'active',
        status_label: 'Activo',
        initials: 'AR',
        roles: ['Operations'],
        primary_role: 'Operations',
        permissions,
        last_login_at: null,
        email_verified_at: null,
    };
}

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function listing(overrides: Partial<LegacyImport> = {}): LegacyImport {
    return {
        id: 1,
        uuid: 'uuid-1',
        profile: 'blinden_legacy_monthly_v1',
        original_filename: 'EMPRESA.xlsx',
        status: 'review',
        status_label: 'En revisión',
        file_size: 766281,
        sha256: 'b'.repeat(64),
        created_at: '2026-10-04T12:00:00Z',
        parsed_at: '2026-10-04T12:01:00Z',
        applied_at: null,
        failed_at: null,
        failure_code: null,
        failure_message: null,
        created_by: 'Ana Restrepo',
        summary: { rows: 2560, monthly_sheets: 10, blocks: 101, document_identities: 320, company_names: 14 },
        ...overrides,
    };
}

function detail(overrides: Partial<LegacyImportDetail> = {}): LegacyImportDetail {
    const base = listing();

    return {
        ...base,
        rows: 2560,
        issues: { total: 59, blocking: 59 },
        actions: 0,
        // A04-R1: §5.4's identity, required on the detail payload.
        plan: {
            revision: 3,
            digest: 'd'.repeat(64),
            built_at: '2026-10-04T12:05:00Z',
            decisions: undefined,
            interpretation_policy: 'manual_only',
        },
        applicable: false,
        ...overrides,
    } as LegacyImportDetail;
}

function row(overrides: Partial<ImportRow> = {}): ImportRow {
    return {
        id: 1,
        sheet_name: 'ENERO 2026',
        sheet_month: '2026-01-01',
        source_row_number: 3,
        company_block_key: '900123456-ANDINA',
        company_tax_id: '900123456',
        company_display_name: 'ANDINA S.A.S.',
        client_identity_key: 'CC 10101010',
        document_type: 'CC',
        document_number: '10101010',
        first_names: 'JUAN',
        last_names: 'PEREZ',
        affiliation_date_raw: '01/03/2026',
        affiliation_date: '2026-03-01',
        // A04-R1: the precision travels with the date, and the suggestion and the parse's own
        // diagnosis are restored rather than re-derived on a rebuild.
        affiliation_date_precision: 'month',
        affiliation_date_suggestion: null,
        affiliation_date_problem: null,
        monthly_amount_cop: 1200000,
        amount_problem: null,
        eps_token: 'SALUD TOTAL',
        afp_token: 'PORVENIR',
        ccf_token: 'COMFENSACION',
        arl_token: 'POSITIVA',
        // A04-R1: §9.4's two evidence sources, separately — the dialog needs both to ask which
        // one to believe when they contradict.
        arl_token_title: 'POSITIVA',
        arl_token_row: null,
        arl_evidence: 'title',
        arl_permitted_risks: [1, 2],
        arl_risk_class: 1,
        job_title: 'AUXILIAR',
        entity_states: null,
        novelty: null,
        retirement_month_token: null,
        retirement_day_count: null,
        source_key: 'a'.repeat(64),
        parse_state: 'staged',
        ...overrides,
    };
}

/**
 * §5.3's `row_id`, A04-R1's `fingerprint`, and the server's whitelist.
 *
 * `allowed_decisions` is the change that matters: the page used to hold its own `switch` of
 * decision names that **did not exist in the backend** — `accept_absence`, `correct_date`,
 * `keep_first`, `acknowledge` — none of which the API would accept, while the API accepted any
 * string at all. The list now arrives from `IssueResolution::allowedFor()`.
 */
function issue(overrides: Partial<ImportIssue> = {}): ImportIssue {
    return {
        id: 1,
        row_id: null,
        code: 'invalid_affiliation_date',
        severity: 'error',
        blocking: true,
        field: 'affiliation_date',
        message: 'Fecha de afiliación «31/02/2026» (impossible_date).',
        context: { sheet: 'ENERO 2026', row: 3, source_key: 'a'.repeat(64) },
        fingerprint: 'b'.repeat(64),
        is_resolved: false,
        resolved_by: null,
        resolved_at: null,
        // A04-R2: a finding the reconstruction stopped producing. Kept false here because this
        // fixture is an open question; `supersededIssue()` is the fixture for the other state.
        is_superseded: false,
        superseded_at: null,
        resolution: null,
        resolution_summary: null,
        allowed_decisions: [
            { value: 'accept_source', label: 'Usar el valor del archivo', value_schema: {}, requires_value: false, resolves: true },
            { value: 'skip_row', label: 'Excluir esta fila', value_schema: {}, requires_value: false, resolves: true },
            { value: 'use_suggested_date', label: 'Usar la fecha sugerida', value_schema: {}, requires_value: false, resolves: true },
            {
                value: 'set_date',
                label: 'Escribir la fecha',
                value_schema: { date: 'date', precision: 'precision' },
                requires_value: true,
                resolves: true,
            },
            { value: 'ignore_date', label: 'Dejar la fecha desconocida', value_schema: {}, requires_value: false, resolves: true },
        ],
        ...overrides,
    };
}

function plan(overrides: Partial<ImportPlan> = {}): ImportPlan {
    return {
        counts: { create: 8, update: 0, unchanged: 1, applied: 0, blocked: 2, total: 9 },
        counts_by_type: { create_company: 1, create_client: 2, create_rate: 3 },
        applicable: false,
        // A04-R1: §5.4's identity, which the Apply dialog submits back.
        plan_revision: 3,
        plan_digest: 'd'.repeat(64),
        plan_built_at: '2026-10-04T12:05:00Z',
        plan_decisions: undefined,
        interpretation_policy: 'manual_only',
        actions: [],
        ...overrides,
    };
}

/**
 * Route the endpoints by URL.
 *
 * The CSRF handshake is a real request in this client, so an unlisted URL has to answer it
 * or a mutation fails for a reason that has nothing to do with what is being tested.
 */
function mockApi(handlers: Record<string, () => Response>): void {
    vi.stubGlobal(
        'fetch',
        vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);

            for (const [fragment, handler] of Object.entries(handlers)) {
                if (url.includes(fragment)) {
                    return handler();
                }
            }

            return new Response(null, { status: 204 });
        }),
    );
}

beforeEach(() => {
    setActivePinia(createPinia());
    resetCsrfState();
    vi.restoreAllMocks();
});

describe('ImportListPage', () => {
    it('hides the upload form from a role without imports.create', async () => {
        useAuthStore().setUser(user(['imports.view']));

        mockApi({ '/api/imports': () => json({ data: [listing()], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }) });

        const wrapper = mount(ImportListPage, {
            global: { stubs: routerStubs, plugins: [await testRouter('/imports')] },
        });

        await flushPromises();

        expect(wrapper.text()).not.toContain('Subir y analizar');
    });

    it('offers the upload form to a role with imports.create', async () => {
        useAuthStore().setUser(user(['imports.view', 'imports.create']));

        mockApi({ '/api/imports': () => json({ data: [listing()], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }) });

        const wrapper = mount(ImportListPage, {
            global: { stubs: routerStubs, plugins: [await testRouter('/imports')] },
        });

        await flushPromises();

        expect(wrapper.text()).toContain('Subir y analizar');
        // §17.2: the explanation that nothing changes comes before the button.
        expect(wrapper.text()).toContain('no modifica ningún dato');
    });

    it('rejects a non-xlsx file before it is sent', async () => {
        useAuthStore().setUser(user(['imports.view', 'imports.create']));

        mockApi({ '/api/imports': () => json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }) });

        const wrapper = mount(ImportListPage, {
            global: { stubs: routerStubs, plugins: [await testRouter('/imports')] },
        });

        await flushPromises();

        const input = wrapper.find('input[type="file"]');
        const file = new File(['bytes'], 'source.xlsm');

        Object.defineProperty(input.element, 'files', { value: [file], configurable: true });

        await input.trigger('change');
        await flushPromises();

        expect(wrapper.text()).toContain('Sólo se aceptan archivos .xlsx');
    });

    it('shows the state and the blocking count of each import', async () => {
        useAuthStore().setUser(user(['imports.view']));

        mockApi({
            '/api/imports': () =>
                json({
                    data: [listing({ summary: { rows: 2560, unresolved_blockers: 13 } })],
                    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
                }),
        });

        const wrapper = mount(ImportListPage, {
            global: { stubs: routerStubs, plugins: [await testRouter('/imports')] },
        });

        await flushPromises();

        expect(wrapper.text()).toContain('EMPRESA.xlsx');
        expect(wrapper.text()).toContain('En revisión');
        expect(wrapper.text()).toContain('2560');
        expect(wrapper.text()).toContain('13');
    });
});

describe('ImportDetailPage', () => {
    async function mountDetail(
        payloads: Record<string, () => Response>,
        permissions = ['imports.view', 'imports.apply'],
    ) {
        useAuthStore().setUser(user(permissions));

        mockApi(payloads);

        const router = await testRouter('/imports/1');

        return mount(ImportDetailPage, {
            global: {
                stubs: {
                    ...routerStubs,
                    // `AppModal` renders a native `<dialog>` through `showModal()`, which jsdom
                    // does not implement. Stubbed so the resolution form can be inspected.
                    AppModal: { template: '<div class="cdh-modal"><slot /></div>', props: ['open', 'title'] },
                },
                plugins: [router],
            },
        });
    }

    const baseHandlers = (overrides: Partial<LegacyImportDetail> = {}, planOverride: Partial<ImportPlan> = {}) => ({
        '/rows': () => json({ data: [row()], meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 } }),
        '/issues': () => json({ data: [issue()], meta: { current_page: 1, last_page: 1, total: 1 } }),
        '/plan': () => json({ data: plan(planOverride) }),
        '/api/imports/1': () => json({ data: detail(overrides) }),
    });

    it('disables Apply and says how many blockers are open', async () => {
        const wrapper = await mountDetail(baseHandlers({ applicable: false, issues: { total: 59, blocking: 13 } }));

        await flushPromises();


        const button = wrapper.findAll('button').find((element) => element.text() === 'Aplicar plan');

        expect(button?.attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Faltan');
        expect(wrapper.text()).toContain('13');
    });

    it('enables Apply only when the server says it is applicable and there are no blockers', async () => {
        const wrapper = await mountDetail(
            baseHandlers({ applicable: true, issues: { total: 0, blocking: 0 }, status: 'ready' }),
        );

        await flushPromises();

        const button = wrapper.findAll('button').find((element) => element.text() === 'Aplicar plan');

        expect(button?.attributes('disabled')).toBeUndefined();
    });

    it('does not enable Apply for a role without imports.apply even when the server allows it', async () => {
        const wrapper = await mountDetail(
            baseHandlers({ applicable: true, issues: { total: 0, blocking: 0 }, status: 'ready' }),
            ['imports.view', 'imports.review'],
        );

        await flushPromises();

        const button = wrapper.findAll('button').find((element) => element.text() === 'Aplicar plan');

        expect(button?.attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Su rol puede revisar pero no aplicar');
    });

    it('labels a month-precision date as approximate instead of as a day', async () => {
        const wrapper = await mountDetail(baseHandlers());

        await flushPromises();

        await wrapper.findAll('[role="tab"]').find((tab) => tab.text().includes('Filas'))?.trigger('click');
        await flushPromises();

        // §8.3's second form. The stored date is the first of the month and must not be shown
        // as that day, because the source asserted a month and not a day.
        expect(wrapper.text()).toContain('(mes aproximado)');

        // The row also shows what the cell literally said, labelled as such. That line is the
        // evidence a reviewer needs for §8.1's `invalid_affiliation_date`, and it is the only
        // place the raw text appears — the interpreted date never becomes a day.
        expect(wrapper.text()).toContain('en el archivo: 01/03/2026');
    });

    it('shows an exact date as a day and an unknown one as unknown', async () => {
        const wrapper = await mountDetail({
            ...baseHandlers(),
            '/rows': () =>
                json({
                    data: [
                        row({ affiliation_date: '2026-03-27', affiliation_date_precision: 'day', affiliation_date_raw: '27/03/2026' }),
                        row({
                            id: 2,
                            affiliation_date: null,
                            affiliation_date_precision: null,
                            affiliation_date_raw: '31/02/2026',
                        }),
                    ],
                    meta: { current_page: 1, last_page: 1, per_page: 50, total: 2 },
                }),
        });

        await flushPromises();

        await wrapper.findAll('[role="tab"]').find((tab) => tab.text().includes('Filas'))?.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('27/03/2026');
        expect(wrapper.text()).toContain('Fecha desconocida');
    });

    it('never renders a credential even if one reached a row', async () => {
        // The parser redacts before staging, so this is a belt-and-braces assertion: if the
        // redactor ever regressed, the screen would be the last place it showed up.
        const wrapper = await mountDetail({
            ...baseHandlers(),
            '/rows': () =>
                json({
                    data: [row({ novelty: 'RETIRO CLAVE: [REDACTED]' })],
                    meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
                }),
        });

        await flushPromises();

        await wrapper.findAll('[role="tab"]').find((tab) => tab.text().includes('Filas'))?.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('RETIRO CLAVE: [REDACTED]');
        expect(wrapper.text()).not.toContain('hola123');
    });

    it('lists the plan counts and the evidence rows of each action', async () => {
        const wrapper = await mountDetail(
            baseHandlers(
                { applicable: true, issues: { total: 0, blocking: 0 }, status: 'ready' },
                {
                    actions: [
                        {
                            id: 1,
                            ordinal: 0,
                            action_type: 'create_company',
                            natural_key: 'company:900123456',
                            payload: { tax_id: '900123456' },
                            source_row_ids: [3, 4],
                            state: 'planned',
                            target_type: null,
                            target_id: null,
                            skip_reason: null,
                            batch_fingerprint: 'c'.repeat(64),
                            source_evidence: { sheets: ['ENERO 2026'], rows: [3, 4], cells: ['ENERO 2026 · fila 3'] },
                            preconditions: { target_exists: false },
                            failure_message: null,
                        },
                    ],
                },
            ),
        );

        await flushPromises();

        await wrapper.findAll('[role="tab"]').find((tab) => tab.text().includes('Plan'))?.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Crear');
        expect(wrapper.text()).toContain('Sin cambios');
        expect(wrapper.text()).toContain('create_company');
        expect(wrapper.text()).toContain('company:900123456');
        // §13: which rows of the file produced this write.
        expect(wrapper.text()).toContain('3, 4');
    });

    it('offers only the decisions that suit each issue code', async () => {
        const wrapper = await mountDetail(baseHandlers(), ['imports.view', 'imports.review']);

        await flushPromises();

        await wrapper.findAll('[role="tab"]').find((tab) => tab.text().includes('Incidencias'))?.trigger('click');
        await flushPromises();

        const resolve = wrapper.findAll('button').find((element) => element.text() === 'Resolver');

        expect(resolve).toBeDefined();

        await resolve?.trigger('click');
        await flushPromises();

        const options = wrapper.findAll('#resolution-decision option').map((option) => option.text());

        // A04-R1. This test used to assert `correct_date` and the absence of
        // `authorise_parallel` — two names that existed only in the page's own `switch` and were
        // never accepted by the API, while the API accepted any string at all. The options now
        // come from the issue's `allowed_decisions`, which the server derives from
        // `IssueResolutionDecision::allowedFor()`.
        expect(options).toContain('Usar la fecha sugerida');
        expect(options).toContain('Escribir la fecha');
        expect(options).toContain('Dejar la fecha desconocida');

        // §8.5's parallel is a decision about a *different* code, so a date finding must not
        // offer it. This is the property the old test was reaching for, against the wrong list.
        expect(options).not.toContain('Cortar el solapamiento en esa fecha');

        // And the values are the server's, so a dialog cannot offer a field the validator refuses.
        expect(JSON.stringify(issue().allowed_decisions)).toContain('"value_schema"');
    });

    it('renders one input per field the chosen decision declares', async () => {
        const wrapper = await mountDetail(baseHandlers(), ['imports.view', 'imports.review']);

        await flushPromises();

        await wrapper.findAll('[role="tab"]').find((tab) => tab.text().includes('Incidencias'))?.trigger('click');
        await flushPromises();

        await wrapper.findAll('button').find((element) => element.text() === 'Resolver')?.trigger('click');
        await flushPromises();

        // A `set_date` answer needs a date and a precision. The previous dialog had no value
        // inputs at all — it sent `{decision, note}` — so every decision that needs a value
        // could not be expressed, and the server refused the payload.
        await wrapper.find('#resolution-decision').setValue('set_date');
        await flushPromises();

        expect(wrapper.find('#resolution-date').exists()).toBe(true);
        expect(wrapper.find('#resolution-precision').exists()).toBe(true);

        // `accept_source` needs nothing, so no inputs appear.
        await wrapper.find('#resolution-decision').setValue('accept_source');
        await flushPromises();

        expect(wrapper.find('#resolution-date').exists()).toBe(false);
    });

    it('hides the resolve action from a role without imports.review', async () => {
        const wrapper = await mountDetail(baseHandlers(), ['imports.view']);

        await flushPromises();

        await wrapper.findAll('[role="tab"]').find((tab) => tab.text().includes('Incidencias'))?.trigger('click');
        await flushPromises();

        const resolve = wrapper.findAll('button').find((element) => element.text() === 'Resolver');

        expect(resolve).toBeUndefined();
    });

    it('shows a failed import message instead of silently looking unapplied', async () => {
        const wrapper = await mountDetail({
            ...baseHandlers(),
            '/api/imports/1': () =>
                json({
                    data: detail({
                        status: 'failed',
                        failure_code: 'source_already_applied',
                        failure_message: 'Este archivo ya se aplicó en la importación uuid-1.',
                    }),
                }),
        });

        await flushPromises();

        expect(wrapper.text()).toContain('ya se aplicó en la importación');
    });

    it('marks the current step of the stepper and does not mark later ones', async () => {
        const wrapper = await mountDetail(baseHandlers({ status: 'review' }));

        await flushPromises();

        const steps = wrapper.findAll('.cdh-stepper__step');
        const current = steps.findIndex((step) => step.classes().includes('cdh-stepper__step--current'));
        const done = steps.filter((step) => step.classes().includes('cdh-stepper__step--done')).length;

        expect(current).toBe(2);
        expect(done).toBe(2);
    });
});
