import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';

import ImportDetailPage from '@/pages/imports/ImportDetailPage.vue';
import ImportListPage from '@/pages/imports/ImportListPage.vue';
import { resetCsrfState } from '@/services/http';
import { useAuthStore } from '@/stores/auth';
import type { AuthUser, ImportIssue, ImportIssueGroup, ImportPlan, ImportRow, LegacyImport, LegacyImportDetail } from '@/types/api';

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
        customFetch?: (input: RequestInfo | URL) => Promise<Response>,
    ) {
        useAuthStore().setUser(user(permissions));

        mockApi(payloads);

        // FIX-EXPORT-IMPORT-03: a test that needs to answer *differently on successive calls* —
        // which is what reproducing an out-of-order response means — cannot express it through the
        // fragment/handler map above, because that map answers the same way every time. The second
        // argument installs its own `fetch` after `mockApi()` and before `mount()`, so the component
        // under test never sees the map.
        if (customFetch !== undefined) {
            vi.stubGlobal('fetch', vi.fn(customFetch));
        }

        const router = await testRouter('/imports/1');

        return mount(ImportDetailPage, {
            global: {
                stubs: {
                    ...routerStubs,
                    // `AppModal` renders a native `<dialog>` through `showModal()`, which jsdom
                    // does not implement. Stubbed so the resolution form can be inspected.
                    // A stand-in that behaves like the real modal rather than a shell.
                    //
                    // It renders its **title**, because several modals are mounted at once whether
                    // or not they are open and `find('.cdh-modal')` would always return the first
                    // one in the template — the Apply confirmation — whichever dialog the test
                    // meant.
                    //
                    // And it renders its **footer buttons wired to the emits**, because a stub that
                    // only renders the slot cannot fire `@confirm`. That is the wiring under test
                    // for the cancellation flow: the button the reviewer presses has to reach the
                    // handler, and a stub that quietly dropped it would let a broken page pass.
                    AppModal: {
                        template: `
                            <div class="cdh-modal">
                                <h2 class="cdh-modal__title">{{ title }}</h2>
                                <slot />
                                <button type="button" @click="$emit('cancel')">{{ cancelLabel }}</button>
                                <button type="button" :disabled="disabled" @click="$emit('confirm')">
                                    {{ confirmLabel }}
                                </button>
                            </div>
                        `,
                        props: ['open', 'title', 'confirmLabel', 'cancelLabel', 'busy', 'disabled'],
                        emits: ['confirm', 'cancel'],
                    },
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

    // -------------------------------------------------------------------------
    // FIX-IMPORT-02 Correction J — the grouped review summary.
    //
    // The numbers below are the delivered workbook's, and they are the point of the whole
    // milestone: 195 findings, of which 153 are informational and 42 are questions. Before the
    // grouping every one of the 195 carried a "Resolver" button, and the 77 invalid email
    // addresses offered exactly one way to be answered — "excluir fila" — so clearing them
    // would have dropped 77 clients from the import.
    // -------------------------------------------------------------------------

    /**
     * A role that can review.
     *
     * `mountDetail`'s default deliberately has no `imports.review` — it exists for the test that
     * asserts the button is hidden from a role without it — so a test that needs the button has
     * to ask for it. Without this the assertions below pass or fail for the wrong reason, which
     * is how "0 Resolver buttons" can mean either "correctly suppressed" or "no permission".
     */
    const REVIEWER = ['imports.view', 'imports.apply', 'imports.review'];

    /**
     * Wait past the code filter's debounce.
     *
     * `flushPromises()` drains the microtask queue and nothing else; the 300 ms debounce is a
     * macrotask, so a test that sets the filter and flushes is asserting against a request that has
     * not been made yet. This is the only place in the suite that waits on wall-clock time, and it
     * is here because the debounce is the behaviour under test.
     */
    const afterDebounce = async (): Promise<void> => {
        await new Promise((resolve) => setTimeout(resolve, 350));
        await flushPromises();
    };

    const openIssuesTab = async (wrapper: VueWrapper) => {
        await flushPromises();

        await wrapper.findAll('[role="tab"]').find((tab) => tab.text().includes('Incidencias'))?.trigger('click');
        await flushPromises();
    };

    // -------------------------------------------------------------------------
    // FIX-EXPORT-IMPORT-03 Block A — the three categories, and the Resolver rule.
    //
    // The numbers below are the delivered workbook's, and they are the point of the whole
    // milestone: 195 findings that are 3 blockers, 38 optional observations and 154 with
    // nothing to answer.
    // -------------------------------------------------------------------------

    const group = (over: Partial<ImportIssueGroup> = {}): ImportIssueGroup => ({
        code: 'invalid_email',
        label: 'Correos omitidos por formato inválido',
        severity: 'warning',
        blocking: false,
        total: 77,
        open: 77,
        resolvable: false,
        ...over,
    });

    /**
     * A finding whose `allowed_decisions` match its category.
     *
     * FIX-EXPORT-IMPORT-03 made the "Resolver" button read `allowed_decisions` instead of the
     * grouped `resolvable` flag, so a fixture with the wrong decisions would test the wrong rule.
     * `SettlesNothing` is the shape the backend sends for an informational finding: no decision at
     * all, or none that resolves it.
     */
    const SettlesNothing: ImportIssue['allowed_decisions'] = [];

    const Blocking: ImportIssue['allowed_decisions'] = [
        {
            value: 'use_company_nit',
            label: 'Elegir el NIT',
            value_schema: { tax_id: 'tax_id' },
            requires_value: true,
            resolves: true,
        },
    ];

    const Optional: ImportIssue['allowed_decisions'] = [
        {
            value: 'map_entity',
            label: 'Mapear la entidad',
            value_schema: { social_security_entity_id: 'positive_integer' },
            requires_value: true,
            resolves: true,
        },
    ];

    const serving = (groups: ImportIssueGroup[], findings: ImportIssue[]) => ({
        ...baseHandlers(),
        '/issues': () =>
            json({
                data: findings,
                meta: { current_page: 1, last_page: 1, per_page: 50, total: findings.length },
                groups,
            }),
    });

    const REAL_WORLD = () =>
        serving(
            [
                group({ code: 'company_identity_conflict', label: 'Conflictos de identidad empresarial', severity: 'error', blocking: true, total: 2, open: 2, resolvable: true }),
                group({ code: 'duplicate_conflicting_row', label: 'Filas repetidas con datos distintos', severity: 'error', blocking: true, total: 1, open: 1, resolvable: true }),
                group({ code: 'affiliation_entity_unknown', label: 'Entidad de afiliación pendiente de identificar', total: 36, open: 36, resolvable: true }),
                group({ code: 'invalid_affiliation_date', label: 'Fechas de afiliación a revisar', total: 2, open: 2, resolvable: true }),
                group({ code: 'invalid_email', total: 77, open: 77, resolvable: false }),
                group({ code: 'missing_monthly_value', label: 'Valores mensuales desconocidos', total: 56, open: 56, resolvable: false }),
                group({ code: 'credential_like_content', label: 'Posibles credenciales protegidas', total: 20, open: 20, resolvable: false }),
                group({ code: 'duplicate_exact_row', label: 'Filas repetidas, consolidadas', total: 1, open: 1, resolvable: false }),
            ],
            [
                issue({ id: 1, code: 'company_identity_conflict', allowed_decisions: Blocking }),
                issue({ id: 2, code: 'affiliation_entity_unknown', allowed_decisions: Optional }),
                issue({ id: 3, code: 'invalid_email', allowed_decisions: SettlesNothing }),
            ],
        );

    /**
     * FIX-IMPORT-REVIEW: Summary, Incidencias and the Apply button must agree.
     *
     * ## The failure this pins
     *
     * Summary used to print `summary.blocking_issues` and `summary.warning_issues`, which
     * `ParsedWorkbook::fingerprint()` computes **while parsing** — before the plan exists, and
     * never revised. On the delivered workbook the two tabs disagreed and both were describing
     * different things:
     *
     *     Resumen      3 bloqueos, 172 advertencias
     *     Incidencias  16 bloqueantes, 63 opcionales, 154 informativas
     *
     * Apply is governed by the second set. So the figure labelled "Bloqueos" was the one number
     * on the screen that the screen's own button did not obey, and a reviewer comparing the two
     * tabs had no way to tell which one was real.
     *
     * The fixture below is those exact numbers, so the test fails if either side drifts back to
     * the parse-time aggregate.
     */
    it('reports the outstanding findings in Summary, not the parse-time aggregates', async () => {
        const wrapper = await mountDetail(
            REAL_WORLD(),
            ['imports.view', 'imports.apply'],
        );

        await flushPromises();

        // The three buckets the Incidencias tab renders, read from the same grouped findings.
        //
        // The *second* grid: the first is the file's own metrics (sheets, rows, identities),
        // which are about the workbook rather than about outstanding work.
        const summary = wrapper.findAll('.cdh-stat-grid')[1] ?? wrapper.find('.cdh-stat-grid');

        expect(summary.text()).toContain('Bloquean la importación');
        expect(summary.text()).toContain('Opcionales');
        expect(summary.text()).toContain('Informativas');

        // 2 company identity + 1 duplicate, both blocking and resolvable.
        // 36 affiliation mappings + 2 dates = 38 answerable but skippable.
        // 77 + 56 + 20 + 1 = 154 with no settling decision.
        expect(summary.text()).toContain('3');
        expect(summary.text()).toContain('38');
        expect(summary.text()).toContain('154');

        // The parse-time figures must not be presented as the batch's state. They are still
        // available, because they describe the file as first read — but only under a heading
        // that says so.
        expect(wrapper.text()).toContain('Métricas del análisis inicial del archivo');
    });

    it('keeps the parse-time aggregates out of the figures Apply obeys', async () => {
        const wrapper = await mountDetail(
            {
                ...REAL_WORLD(),
                '/api/imports/1': () =>
                    json({
                        data: detail({
                            applicable: false,
                            // The delivered workbook's `issues.total`, and the live blocker count
                            // the server computes from `blocking AND resolved_at IS NULL`.
                            issues: { total: 233, blocking: 16 },
                            // `ParsedWorkbook::fingerprint()` on the same file: 3 parse-time
                            // blockers and 172 parse-time warnings. The whole contradiction, and
                            // the two sets have nothing in common — which is the point.
                            summary: { blocking_issues: 3, warning_issues: 172, rows: 2559 },
                        }),
                    }),
            },
            ['imports.view', 'imports.apply'],
        );

        await flushPromises();

        const button = wrapper.findAll('button').find((element) => element.text() === 'Aplicar plan');

        // Apply is disabled, because the server says this batch has 16 open blockers.
        expect(button?.attributes('disabled')).toBeDefined();

        // Summary must not be reporting the parse's 3, nor the raw total of 233. It reports the
        // three buckets from the grouped findings: 3 answerable blockers, 38 skippable
        // questions, 154 informational.
        const summary = wrapper.findAll('.cdh-stat-grid')[1] ?? wrapper.find('.cdh-stat-grid');
        const text = summary.text();

        expect(text).toContain('Bloquean la importación');
        expect(text).toContain('3');
        expect(text).toContain('38');
        expect(text).toContain('154');

        // 233 is the *total recorded*, shown in its own bucket — never as "what is blocking you".
        expect(text).toContain('233');

        // The reconciliation sentence, so the four numbers can be checked against each other.
        expect(wrapper.text()).toContain('ya están respondidas o fueron retiradas');
    });

    it('splits the findings into blockers, optional observations and informational', async () => {
        const wrapper = await mountDetail(REAL_WORLD(), REVIEWER);

        await openIssuesTab(wrapper);

        expect(wrapper.text()).toContain('Bloquean la importación');
        expect(wrapper.text()).toContain('Opcionales — puede continuar sin responderlas');
        expect(wrapper.text()).toContain('Informativas — no requieren ninguna acción');

        // The delivered workbook's numbers, which is what a reviewer needs to see before the
        // table: 3 blockers, 38 optional, 154 with nothing to answer.
        const summary = wrapper.find('.cdh-issue-summary').text().replace(/\s+/g, ' ');

        expect(summary).toContain('3');
        expect(summary).toContain('38');
        expect(summary).toContain('154');
    });

    it('collapses the informational block by default and expands it on demand', async () => {
        // 154 rows of "invalid email" is not something anybody reads, and the previous screen made
        // them scroll all of it to reach the three that mattered.
        const wrapper = await mountDetail(REAL_WORLD(), REVIEWER);

        await openIssuesTab(wrapper);

        expect(wrapper.text()).not.toContain('Posibles credenciales protegidas');

        const toggle = wrapper
            .findAll('button')
            .find((element) => element.text().includes('Informativas'));

        expect(toggle?.attributes('aria-expanded')).toBe('false');

        await toggle?.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Posibles credenciales protegidas');
        expect(
            wrapper
                .findAll('button')
                .find((element) => element.text().includes('Informativas'))
                ?.attributes('aria-expanded'),
        ).toBe('true');
    });

    it('offers Resolver only where the backend offers a decision that resolves', async () => {
        // The rule the previous version got backwards: it derived the button from the grouped
        // `resolvable` flag with a fallback that showed it when the breakdown was *missing*.
        const wrapper = await mountDetail(REAL_WORLD(), REVIEWER);

        await openIssuesTab(wrapper);

        const rows = wrapper.findAll('tbody tr');

        // The blocker and the optional observation carry a settling decision; the informational
        // one carries none.
        expect(wrapper.findAll('button').filter((b) => b.text() === 'Resolver')).toHaveLength(2);

        const informationalRow = rows.find((row) => row.text().includes('invalid_email'));

        expect(informationalRow?.text()).toContain('Nada que responder');
    });

    it('offers no Resolver at all when the breakdown is missing', async () => {
        // The inverted fallback, asserted directly. `resolvableCodes.size === 0 || …` meant a failed
        // or incomplete response put a "Resolver" button on every row — on the delivered workbook,
        // 77 of them whose only answer is "excluir fila".
        //
        // The button now reads each finding's own `allowed_decisions`, so a response with no
        // groups still answers correctly for the findings it does carry.
        const wrapper = await mountDetail(
            {
                ...baseHandlers(),
                '/issues': () =>
                    json({
                        data: [issue({ id: 3, code: 'invalid_email', allowed_decisions: SettlesNothing })],
                        meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
                    }),
            },
            REVIEWER,
        );

        await openIssuesTab(wrapper);

        expect(wrapper.findAll('button').filter((b) => b.text() === 'Resolver')).toHaveLength(0);
    });

    it('says the request failed rather than showing an empty list', async () => {
        // "No hay incidencias" and "no pudimos preguntar" look identical on a screen, and only one
        // of them means it is safe to press Apply.
        const wrapper = await mountDetail(
            {
                ...baseHandlers(),
                '/issues': () => json({ message: 'Error interno del servidor.' }, 500),
            },
            REVIEWER,
        );

        await openIssuesTab(wrapper);

        expect(wrapper.text()).toContain('No se pudieron cargar las incidencias');
        expect(wrapper.text()).not.toContain('No hay incidencias pendientes');
        // And there is a way back, rather than a dead end.
        expect(
            wrapper.findAll('button').some((element) => element.text().includes('Reintentar')),
        ).toBe(true);
    });

    it('does not let a stale response overwrite a newer filter', async () => {
        // `loadIssues()` had no sequencing and is called from the initial load and from three
        // watchers. Two requests overlap, the slow one lands last, and the table ends up showing
        // the previous filter's rows under the new filter's label — which is exactly the reported
        // symptom: the code field said one thing and the table showed another.
        //
        // The overlap is driven from the **initial load** rather than from typing, because the code
        // filter is debounced: two keystrokes 100 ms apart collapse into one request, so typing is
        // no longer a way to produce the race. The initial load is, and it is the realistic one —
        // a reviewer changes the filter while the first page of findings is still in flight.
        const slow = { release: (): void => {} };
        const pending = new Promise<void>((resolve) => {
            slow.release = resolve;
        });

        let firstCall = true;

        const racingFetch = async (input: RequestInfo | URL): Promise<Response> => {
            const url = String(input);

            if (url.includes('/api/company-options')) {
                return json({ companies: [] });
            }

            if (url.includes('/issues')) {
                // The initial load, held open until the filter's response has landed.
                if (firstCall) {
                    firstCall = false;

                    await pending;

                    return json({
                        data: [issue({ id: 9, code: 'invalid_email', allowed_decisions: SettlesNothing })],
                        meta: { current_page: 1, last_page: 1, total: 1 },
                        groups: [group({ code: 'invalid_email', total: 1, open: 1 })],
                    });
                }

                return json({
                    data: [issue({ id: 8, code: 'company_identity_conflict', allowed_decisions: Blocking })],
                    meta: { current_page: 1, last_page: 1, total: 1 },
                    groups: [
                        group({
                            code: 'company_identity_conflict',
                            label: 'Conflictos de identidad empresarial',
                            severity: 'error',
                            blocking: true,
                            total: 1,
                            open: 1,
                            resolvable: true,
                        }),
                    ],
                });
            }

            if (url.includes('/plan')) {
                return json({ data: plan() });
            }

            if (url.includes('/api/imports/1')) {
                return json({ data: detail() });
            }

            return json({ data: [row()], meta: { current_page: 1, last_page: 1, total: 1 } });
        };

        const wrapper = await mountDetail({}, REVIEWER, racingFetch);

        await openIssuesTab(wrapper);

        // While the first request is still in flight, ask for a different kind.
        const code = wrapper.find('#issues-code');

        await code.setValue('company_identity_conflict');
        await afterDebounce();

        // The filter's response has landed and is on screen.
        expect(wrapper.findAll('tbody tr').some((row) => row.text().includes('company_identity_conflict'))).toBe(
            true,
        );

        // Now let the stale one arrive. It must be discarded, not applied.
        slow.release();
        await afterDebounce();
        await flushPromises();

        const shown = wrapper.findAll('tbody tr').map((row) => row.text());

        expect(shown.some((text) => text.includes('invalid_email'))).toBe(false);
        expect(shown.some((text) => text.includes('company_identity_conflict'))).toBe(true);
    });

    it('narrows the list to the kind that was clicked', async () => {
        const wrapper = await mountDetail(
            serving(
                [group({ code: 'invalid_email', total: 77, open: 77, resolvable: false })],
                [issue({ id: 3, code: 'invalid_email', allowed_decisions: SettlesNothing })],
            ),
            REVIEWER,
        );

        await openIssuesTab(wrapper);

        // Informational groups are collapsed, so the block is opened first — the same two clicks a
        // reviewer makes.
        const toggle = wrapper
            .findAll('button')
            .find((element) => element.text().includes('Informativas'));

        await toggle?.trigger('click');
        await flushPromises();

        const groupButton = wrapper
            .findAll('button')
            .find((element) => element.text().includes('Correos omitidos por formato inválido'));

        await groupButton?.trigger('click');
        await afterDebounce();

        // Reuses the existing code filter rather than adding a second narrowing mechanism, so
        // there is one place the list is filtered and one control that shows what it is.
        expect((wrapper.find('#issues-code').element as HTMLInputElement).value).toBe('invalid_email');
        expect(wrapper.text()).toContain('Quitar filtros');
    });
});

// =============================================================================
// FIX-EXPORT-IMPORT-03 Block B — cancelling an import
// =============================================================================

describe('cancelling an import', () => {
    /**
     * The states the lifecycle allows, and the two it refuses.
     *
     * `applied` is the one that matters: cancelling it would be a route to rolling back business
     * data without going through anything that reverses a write, and `ImportLifecycle::ALLOWED`
     * gives it an empty transition list. `cancelled` is terminal, and `applying` is mid-transaction.
     */
    const REVIEW = ['imports.view', 'imports.apply', 'imports.review'];

    /**
     * Mount the detail page against a batch in `status`, recording every cancel call.
     *
     * Self-contained rather than reusing the other describe's `mountDetail`, which is scoped to it.
     */
    const mounted = async (status: string, permissions: string[]) => {
        const calls: string[] = [];

        useAuthStore().setUser(user(permissions));

        vi.stubGlobal(
            'fetch',
            vi.fn(async (input: RequestInfo | URL) => {
                const url = String(input);

                if (url.includes('/api/company-options')) {
                    return json({ companies: [] });
                }

                if (url.includes('/issues')) {
                    return json({ data: [], meta: { current_page: 1, last_page: 1, total: 0 }, groups: [] });
                }

                if (url.includes('/plan')) {
                    return json({ data: plan() });
                }

                // Before the catch-all, so the cancel POST is not mistaken for the detail GET.
                if (url.includes('/cancel')) {
                    calls.push(url);

                    return json({ message: 'Importación cancelada.' });
                }

                if (url.includes('/api/imports/1')) {
                    return json({ data: detail({ status } as Partial<LegacyImportDetail>) });
                }

                return json({ data: [row()], meta: { current_page: 1, last_page: 1, total: 1 } });
            }),
        );

        const router = await testRouter('/imports/1');

        const wrapper = mount(ImportDetailPage, {
            global: {
                stubs: {
                    ...routerStubs,
                    // The title is rendered so a test can say *which* dialog it is looking at.
                    // Several modals are mounted at once whether or not they are open, so
                    // `find('.cdh-modal')` alone would always return the first one in the
                    // template — the Apply confirmation — whichever dialog the test meant.
                    AppModal: {
                        template: `
                            <div class="cdh-modal">
                                <h2 class="cdh-modal__title">{{ title }}</h2>
                                <slot />
                                <button type="button" @click="$emit('cancel')">{{ cancelLabel }}</button>
                                <button type="button" :disabled="disabled" @click="$emit('confirm')">
                                    {{ confirmLabel }}
                                </button>
                            </div>
                        `,
                        props: ['open', 'title', 'confirmLabel', 'cancelLabel', 'busy', 'disabled'],
                        emits: ['confirm', 'cancel'],
                    },
                },
                plugins: [router],
            },
        });

        await flushPromises();

        return { wrapper, calls };
    };

    /** The dialog whose heading names it. See the note on the `AppModal` stub above. */
    const dialogTitled = (wrapper: VueWrapper, title: string) => {
        const match = wrapper
            .findAll('.cdh-modal')
            .find((candidate) => candidate.find('.cdh-modal__title').text() === title);

        if (match === undefined) {
            throw new Error(`No dialog titled "${title}".`);
        }

        return match;
    };

    const clickText = (wrapper: VueWrapper, text: string, scope = 'button'): Promise<unknown> => {
        const target = wrapper.findAll(scope).find((element) => element.text().trim() === text);

        return target?.trigger('click') ?? Promise.resolve();
    };

    it('offers the cancellation on a batch that is still in review', async () => {
        const { wrapper } = await mounted('review', REVIEW);

        // `resumen` is the default tab, so the card under test is already rendered.
        await flushPromises();

        expect(wrapper.findAll('button').some((b) => b.text().trim() === 'Cancelar importación')).toBe(
            true,
        );
    });

    it('says what will and will not happen before cancelling', async () => {
        // The sentence that decides whether somebody presses the button at all is the third one,
        // and it is asserted here rather than trusted to a design review.
        const { wrapper } = await mounted('review', REVIEW);

        // `resumen` is the default tab, so the card under test is already rendered.
        await flushPromises();

        await clickText(wrapper, 'Cancelar importación');
        await flushPromises();

        const dialog = dialogTitled(wrapper, '¿Cancelar esta importación?');

        expect(dialog.text()).toContain('¿Cancelar esta importación?');
        expect(dialog.text()).toContain('dejará de utilizarse');
        expect(dialog.text()).toContain('copia privada del archivo');
        expect(dialog.text()).toContain('No se eliminarán clientes, empresas ni pagos');
        expect(dialog.text()).toContain('quedará registrada para auditoría');
        // Two ways out, and one of them is "go back".
        expect(dialog.text()).toContain('No, volver');
    });

    it('calls the existing endpoint and reports the outcome', async () => {
        const { wrapper, calls } = await mounted('review', REVIEW);

        // `resumen` is the default tab, so the card under test is already rendered.
        await flushPromises();

        await clickText(wrapper, 'Cancelar importación');
        await flushPromises();

        await dialogTitled(wrapper, '¿Cancelar esta importación?')
            .findAll('button')
            .find((element) => element.text().trim() === 'Sí, cancelar')
            ?.trigger('click');
        await flushPromises();

        // The endpoint the backend already had, not a second one.
        expect(calls.some((url) => url.includes('/api/imports/1/cancel'))).toBe(true);
        expect(wrapper.text()).toContain('quedó cancelada');
    });

    it('hides it from a role that may only look', async () => {
        const { wrapper } = await mounted('review', ['imports.view']);

        // `resumen` is the default tab, so the card under test is already rendered.
        await flushPromises();

        expect(wrapper.findAll('button').some((b) => b.text().trim() === 'Cancelar importación')).toBe(
            false,
        );
    });

    it('hides it on an applied batch', async () => {
        // Cancelling an applied import would be a way to undo business data without anything that
        // reverses a write, so the button is never offered.
        const { wrapper } = await mounted('applied', REVIEW);

        // `resumen` is the default tab, so the card under test is already rendered.
        await flushPromises();

        expect(wrapper.findAll('button').some((b) => b.text().trim() === 'Cancelar importación')).toBe(
            false,
        );
    });
});
