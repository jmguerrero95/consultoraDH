import { flushPromises, mount } from '@vue/test-utils';

import type { DOMWrapper, VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import ClientDetailPage from '@/pages/clients/ClientDetailPage.vue';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';

import type { AuthUser, ClientDetailPayload } from '@/types/api';

/**
 * The client detail screen, focusing on the two rules that are easiest to get
 * wrong and most expensive if they are:
 *
 *  - a risk level exists only on an ARL affiliation, and it is offered with the
 *    wording an administrator would say out loud;
 *  - a second open relationship is never created silently. The screen offers the
 *    three resolutions the server sends, and cancelling changes nothing.
 */

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

/**
 * Stub every call with the same body.
 *
 * A Response body can only be read once, so returning one shared instance breaks
 * as soon as the page makes a second request. Each call gets a fresh one.
 */
/**
 * Answer every request with the same body, except the two picker endpoints.
 *
 * Those two have their own shapes, and answering them with the detail payload left
 * `companies` holding an object where a list was about to be rendered, which threw
 * during the update and surfaced as an unhandled rejection rather than as a failed
 * assertion. A suite that passes while three of its components crashed is not
 * reporting on itself honestly.
 */
function alwaysRespond(body: unknown, status = 200): void {
    vi.mocked(fetch).mockImplementation(async (input) => {
        const url = String(input);

        if (url.includes('/api/company-options')) {
            return jsonResponse({ companies: [] });
        }

        if (url.includes('/api/social-security-entities')) {
            return jsonResponse({ entities: [] });
        }

        return jsonResponse(body, status);
    });
}

function detail(overrides: Partial<ClientDetailPayload> = {}): ClientDetailPayload {
    return {
        client: {
            id: 1,
            document_type: 'CC',
            document_type_short: 'C.C.',
            document_number: '12345678',
            document_label: 'CC 12.345.678',
            full_name: 'Ana María Restrepo',
            first_names: 'Ana María',
            last_names: 'Restrepo',
            email: null,
            phone: null,
            address: null,
            city: null,
            department: null,
            status: 'active',
            status_label: 'Activo',
            companies_count: 0,
            companies: [],
            created_at: null,
            updated_at: null,
        },
        risk_options: [
            { value: 1, label: 'Riesgo I' },
            { value: 2, label: 'Riesgo II' },
            { value: 3, label: 'Riesgo III' },
            { value: 4, label: 'Riesgo IV' },
            { value: 5, label: 'Riesgo V' },
        ],
        companies: { visible: true, active: [], history: [] },
        affiliations: { visible: true, active: [], history: [] },
        history: [],
        data_quality: [],
        ...overrides,
    };
}

/** One open ARL affiliation at level III. */
function arlAffiliation(): NonNullable<ClientDetailPayload['affiliations']['active']>[number] {
    return {
        id: 1,
        client_id: 1,
        social_security_entity_id: 2,
        entity: {
            id: 2,
            type: 'ARL',
            type_label: 'ARL',
            name: 'ARL Alfa',
            code: null,
            status: 'active',
            status_label: 'Activo',
        },
        client_company_assignment_id: null,
        type: 'ARL',
        type_label: 'ARL',
        type_full_label: 'Administradora de Riesgos Laborales',
        started_on: '2025-01-01',
        ended_on: null,
        is_active: true,
        arl_risk_class: 3,
        arl_risk_label: 'Riesgo III',
        notes: null,
    };
}

/**
 * Find a button by its visible text, from a list of buttons.
 *
 * `:has-text()` is a Playwright extension that jsdom does not implement, so the
 * matching happens here. Taking a list rather than a wrapper means it works both
 * for the whole page and for the contents of a single dialog.
 */
function button(
    buttons: readonly DOMWrapper<HTMLButtonElement>[],
    label: string,
): DOMWrapper<HTMLButtonElement> {
    const match = buttons.find((candidate) => candidate.text().trim().includes(label));

    if (match === undefined) {
        throw new Error(`No button whose text contains "${label}".`);
    }

    return match;
}

/** Every button currently rendered. */
function buttons(wrapper: VueWrapper): DOMWrapper<HTMLButtonElement>[] {
    return wrapper.findAll<HTMLButtonElement>('button');
}

const OPERATIONS = [
    'clients.view',
    'relationships.view',
    'relationships.manage',
    'affiliations.view',
    'affiliations.manage',
];

function mountPage(permissions: string[] = OPERATIONS) {
    const pinia = createPinia();

    setActivePinia(pinia);

    const auth = useAuthStore();

    auth.setUser({ permissions } as AuthUser);

    // A real router, because the page reads its identifier with `useRoute()`,
    // which needs the plugin installed. A `$route` mock would leave it undefined.
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'home', component: { template: '<div />' } },
            { path: '/clients/:id', name: 'clients.show', component: ClientDetailPage },
            {
                path: '/clients',
                name: 'clients',
                component: { template: '<div />' },
            },
        ],
    });

    void router.push({ name: 'clients.show', params: { id: '1' } });

    const wrapper = mount(ClientDetailPage, {
        global: { plugins: [pinia, router] },
    });

    return { wrapper, auth };
}

beforeEach(() => {
    vi.mocked(fetch).mockReset();
});

describe('the ARL risk level', () => {
    it('offers the five levels with the wording people use, and no more', async () => {
        alwaysRespond(detail());

        const { wrapper } = mountPage();

        await flushPromises();

        await wrapper.get('#tab-afiliaciones').trigger('click');
        await button(buttons(wrapper), 'Registrar afiliación').trigger('click');
        await flushPromises();

        // Switch the type to ARL through the real control.
        await wrapper.get('select[name=affiliation_type]').setValue('ARL');
        await flushPromises();

        const options = wrapper
            .findAll('select[name=affiliation_risk] option')
            .map((option) => option.text());

        expect(options).toContain('Riesgo I');
        expect(options).toContain('Riesgo III');
        // Class V is the highest risk class, and the label comes from the server's
        // enum rather than from a list kept beside it.
        expect(options).toContain('Riesgo V');
        expect(options).not.toContain('No clasificado');
        // Five levels, plus the "not registered" choice.
        expect(options).toHaveLength(6);
    });

    it('does not offer a risk level for an EPS, because only an ARL carries one', async () => {
        alwaysRespond(detail());

        const { wrapper } = mountPage();

        await flushPromises();

        await wrapper.get('#tab-afiliaciones').trigger('click');
        await button(buttons(wrapper), 'Registrar afiliación').trigger('click');
        await flushPromises();

        // EPS is the default type.
        expect(wrapper.find('select[name=affiliation_risk]').exists()).toBe(false);
    });

    it('only offers entities of the selected type', async () => {
        vi.mocked(fetch).mockImplementation(async (input) => {
            const url = String(input);

            // The picker endpoint has its own shape; answering it with the detail
            // payload put an object where the link dialog renders a list.
            if (url.includes('/api/company-options')) {
                return jsonResponse({ companies: [] });
            }

            if (url.includes('/api/social-security-entities')) {
                return jsonResponse({
                    entities: [
                        {
                            id: 1,
                            type: 'EPS',
                            type_label: 'EPS',
                            type_full_label: 'Entidad Prestadora de Salud',
                            name: 'Salud Total',
                            code: null,
                            tax_id: null,
                            status: 'active',
                            status_label: 'Activo',
                            affiliations_count: 0,
                            active_affiliations_count: 0,
                            created_at: null,
                            updated_at: null,
                        },
                        {
                            id: 2,
                            type: 'ARL',
                            type_label: 'ARL',
                            type_full_label: 'Administradora de Riesgos Laborales',
                            name: 'ARL Alfa',
                            code: null,
                            tax_id: null,
                            status: 'active',
                            status_label: 'Activo',
                            affiliations_count: 0,
                            active_affiliations_count: 0,
                            created_at: null,
                            updated_at: null,
                        },
                    ],
                    pagination: {
                        total: 2,
                        per_page: 100,
                        current_page: 1,
                        last_page: 1,
                        from: 1,
                        to: 2,
                    },
                    filters: { search: null, status: null, type: null },
                });
            }

            return jsonResponse(detail());
        });

        const { wrapper } = mountPage();

        await flushPromises();

        await wrapper.get('#tab-afiliaciones').trigger('click');
        await button(buttons(wrapper), 'Registrar afiliación').trigger('click');
        await flushPromises();

        const epsOptions = wrapper
            .findAll('select[name=affiliation_entity] option')
            .map((option) => option.text());

        // Only the EPS is offered while the type is EPS: pointing an ARL
        // affiliation at an EPS entity is refused by the server.
        expect(epsOptions).toContain('Salud Total');
        expect(epsOptions).not.toContain('ARL Alfa');
    });

    it('presents the stored level with its label', async () => {
        alwaysRespond(
            detail({
                affiliations: {
                    visible: true,
                    active: [arlAffiliation()],
                    history: [],
                },
            }),
        );

        const { wrapper } = mountPage();

        await flushPromises();

        await wrapper.get('#tab-afiliaciones').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Riesgo III');
    });
});

describe('a second open company relationship', () => {
    async function openLinkDialogWithConflict() {
        vi.mocked(fetch).mockImplementation(async (input) => {
            const url = String(input);

            if (url.includes('/api/company-options')) {
                return jsonResponse({ companies: [] });
            }

            if (url.includes('/companies') && url.endsWith('/companies')) {
                return jsonResponse(
                    {
                        message:
                            'El cliente ya tiene 1 relación abierta en otra empresa. Debe decidir si '
                            + 'transfiere, si mantiene las dos con justificación, o si cancela.',
                        code: 'parallel_relationship_not_allowed',
                        open_assignments: [7],
                        options: [
                            {
                                value: 'transfer',
                                label: 'Transferir',
                                description: 'Cierra la relación abierta en la fecha efectiva y abre la nueva.',
                                requires_reason: false,
                            },
                            {
                                value: 'parallel',
                                label: 'Mantener en paralelo',
                                description: 'Conserva la relación abierta y agrega la nueva, con justificación.',
                                requires_reason: true,
                            },
                            {
                                value: 'cancel',
                                label: 'Cancelar',
                                description: 'No modifica nada.',
                                requires_reason: false,
                            },
                        ],
                    },
                    409,
                );
            }

            return jsonResponse(detail());
        });

        const { wrapper } = mountPage();

        await flushPromises();

        await wrapper.get('#tab-empresas').trigger('click');
        await button(buttons(wrapper), 'Vincular empresa').trigger('click');
        await flushPromises();

        await wrapper.get('select[name=link_company]').setValue('1');
        await button(wrapper.findAll<HTMLButtonElement>('dialog button'), 'Registrar').trigger('click');
        await flushPromises();

        return { wrapper };
    }

    it('warns and offers the three resolutions instead of proceeding', async () => {
        const { wrapper } = await openLinkDialogWithConflict();

        expect(wrapper.text()).toContain('ya tiene 1 relación abierta');
        expect(wrapper.text()).toContain('Transferir');
        expect(wrapper.text()).toContain('Mantener en paralelo');
        expect(wrapper.text()).toContain('Cancelar');
    });

    it('says which resolution needs a justification', async () => {
        const { wrapper } = await openLinkDialogWithConflict();

        // The parallel choice is the only one that asks for a reason, and the
        // field appears because of that rather than by guessing.
        expect(wrapper.find('[name=link_parallel_reason]').exists()).toBe(false);

        await button(buttons(wrapper), 'Mantener en paralelo').trigger('click');
        await flushPromises();

        expect(wrapper.find('[name=link_parallel_reason]').exists()).toBe(true);
    });

    it('cancels without writing anything', async () => {
        const { wrapper } = await openLinkDialogWithConflict();

        const writesBefore = vi
            .mocked(fetch)
            .mock.calls.filter(([, init]) => (init as RequestInit | undefined)?.method === 'POST').length;

        // The "Cancelar" among the three resolutions, which is the choice the
        // flow is about. It is a choice card, not the dialog footer.
        const choices = wrapper.findAll('.cdh-warning--notice');
        const cancel = choices.find((choice) => choice.text().includes('Cancelar'));

        expect(cancel).toBeDefined();

        await cancel?.trigger('click');
        await flushPromises();

        const writesAfter = vi
            .mocked(fetch)
            .mock.calls.filter(([, init]) => (init as RequestInit | undefined)?.method === 'POST').length;

        // The only POST was the one the server rejected with 409. Cancelling
        // added none.
        expect(writesAfter).toBe(writesBefore);
        expect(wrapper.find('dialog[open]').exists()).toBe(false);
    });

    it('marks an authorised parallel relationship as documented, not as a problem', async () => {
        alwaysRespond(
            detail({
                    data_quality: [
                        {
                            code: 'multiple_active_companies',
                            label: 'Varias empresas activas',
                            severity: 'notice',
                            severity_label: 'Aviso',
                            message: 'El cliente tiene 2 relaciones abiertas: Andina, Coopnorte.',
                            suggestion: 'El paralelismo fue autorizado y queda documentado.',
                            blocking: false,
                        },
                    ],
            }),
        );

        const { wrapper } = mountPage();

        await flushPromises();

        expect(wrapper.text()).toContain('paralelismo fue autorizado');
    });
});

describe('permission controlled actions', () => {
    it('hides the mutation buttons from a role that may only read', async () => {
        alwaysRespond(detail());

        const { wrapper } = mountPage(['clients.view', 'affiliations.view', 'relationships.view']);

        await flushPromises();

        await wrapper.get('#tab-empresas').trigger('click');
        await flushPromises();

        // A control the user can never press should not be in the document.
        expect(buttons(wrapper).some((b) => b.text().includes('Vincular empresa'))).toBe(false);

        // The per row actions are scoped to the table: the dialogs keep their own
        // confirm buttons mounted, so an unscoped search would match those.
        expect(wrapper.findAll('.cdh-table__actions button')).toHaveLength(0);

        await wrapper.get('#tab-afiliaciones').trigger('click');
        await flushPromises();

        expect(buttons(wrapper).some((b) => b.text().includes('Registrar afiliación'))).toBe(false);

        // Scoped to the record header: the dialogs keep their confirm buttons
        // mounted, so an unscoped search would match those instead.
        const headerButtons = wrapper.findAll<HTMLButtonElement>('.cdh-record-header button');

        expect(headerButtons.some((b) => b.text().trim() === 'Desactivar')).toBe(false);
    });

    it('does not offer the affiliations tab when the role has no permission', async () => {
        vi.mocked(fetch).mockResolvedValue(
            jsonResponse(detail({ affiliations: { visible: false } })),
        );

        const { wrapper } = mountPage(['clients.view']);

        await flushPromises();

        // No tab at all, rather than a tab that opens onto a refusal: the
        // permission is the server's, and this only avoids sending somebody to a
        // screen whose answer is "you cannot see this".
        expect(wrapper.find('#tab-afiliaciones').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Entidades');
    });

    it('does not offer the empresas tab when the role has no permission', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(detail({ companies: { visible: false } })));

        const { wrapper } = mountPage(['clients.view', 'affiliations.view']);

        await flushPromises();

        expect(wrapper.find('#tab-empresas').exists()).toBe(false);
        expect(wrapper.find('#tab-afiliaciones').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('Empresas actuales');
    });

    it('shows both tabs to a role holding both permissions', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(detail()));

        const { wrapper } = mountPage(['clients.view', 'relationships.view', 'affiliations.view']);

        await flushPromises();

        expect(wrapper.find('#tab-empresas').exists()).toBe(true);
        expect(wrapper.find('#tab-afiliaciones').exists()).toBe(true);
    });
});

describe('failures', () => {
    it('reports a rejected validation with the server message', async () => {
        vi.mocked(fetch).mockImplementation(async (input) => {
            const url = String(input);

            // Same as above: the picker endpoints answer with lists of their own.
            if (url.includes('/api/company-options')) {
                return jsonResponse({ companies: [] });
            }

            if (url.includes('/api/social-security-entities')) {
                return jsonResponse({ entities: [] });
            }

            if (url.includes('/api/clients/1') && (url.endsWith('/companies'))) {
                return jsonResponse(
                    {
                        message: 'La fecha de cierre no puede ser anterior a la de inicio.',
                        code: 'relationship_rejected',
                        errors: { ended_on: ['La fecha de cierre no puede ser anterior.'] },
                    },
                    422,
                );
            }

            return jsonResponse(detail());
        });

        const { wrapper } = mountPage();

        await flushPromises();

        await wrapper.get('#tab-empresas').trigger('click');
        await button(buttons(wrapper), 'Vincular empresa').trigger('click');
        await flushPromises();

        expect(wrapper.find('dialog[open]').exists()).toBe(true);
    });

    it('shows a load failure rather than an empty record', async () => {
        vi.mocked(fetch).mockRejectedValue(new ApiError({ message: 'No fue posible cargar la ficha.', status: 500 }));

        const { wrapper } = mountPage();

        await flushPromises();

        const alert = wrapper.find('.cdh-alert--danger');

        expect(alert.exists()).toBe(true);
        // Whatever the transport said, the screen shows an error rather than an
        // empty record that reads like a client with nothing on it.
        expect(alert.text()).not.toContain('Sin empresas abiertas');
    });
});