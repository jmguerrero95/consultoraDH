import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import DashboardPage from '@/pages/DashboardPage.vue';
import { useAuthStore } from '@/stores/auth';

import type { AuthUser, DashboardPayload, PortfolioCounts } from '@/types/api';

/**
 * The dashboard under a role that may read only part of the portfolio.
 *
 * The server sends a figure only to a role that may read the section it describes,
 * and every field of `PortfolioCounts` is optional because of that. The obligation
 * this file exists for is the other half of the contract: the interface must not
 * invent a figure the server withheld.
 *
 * Rendering a zero is not a neutral fallback. "0 clientes activos" is a claim about
 * the portfolio, and for a role that may not read the client section it is a false
 * one. An absent figure has to produce no card at all.
 */

function jsonResponse(body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });
}

function dashboard(counts: PortfolioCounts): DashboardPayload {
    return {
        greeting: { name: 'Administrador Prueba', first_name: 'Prueba', date: 'jueves 1 de octubre' },
        user: {
            email: 'prueba@consultora-dh.test',
            primary_role: 'Consulta',
            roles: ['Consulta'],
            status: 'active',
            last_login_at: null,
        },
        application: {
            name: 'Consultora DH',
            version: '1.0.0',
            environment: 'local',
            locale: 'es',
            timezone: 'America/Bogota',
        },
        services: {
            database: { status: 'operational', label: 'PostgreSQL', detail: null },
            redis: { status: 'operational', label: 'Redis', detail: null },
        },
        portfolio: {
            visible: true,
            counts,
        },
    };
}

function mountPage(): { wrapper: ReturnType<typeof mount>; auth: ReturnType<typeof useAuthStore> } {
    const pinia = createPinia();

    setActivePinia(pinia);

    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', component: { template: '<div />' } },
            { path: '/clients', component: { template: '<div />' } },
            { path: '/companies', component: { template: '<div />' } },
        ],
    });

    const wrapper = mount(DashboardPage, {
        global: { plugins: [pinia, router] },
    });

    return { wrapper, auth: useAuthStore() };
}

beforeEach(() => {
    vi.mocked(fetch).mockReset();
});

describe('DashboardPage', () => {
    it('shows every card when the role may read the whole portfolio', async () => {
        vi.mocked(fetch).mockResolvedValue(
            jsonResponse(
                dashboard({
                    active_clients: 12,
                    inactive_clients: 3,
                    active_companies: 7,
                    data_quality_issues: 2,
                    data_quality_warnings: 5,
                    active_relationships: 14,
                    active_affiliations: 16,
                    catalogue_entities: 9,
                }),
            ),
        );

        const { wrapper, auth } = mountPage();

        auth.setUser({ permissions: ['portfolio.view'] } as AuthUser);

        await flushPromises();

        const text = wrapper.text();

        expect(text).toContain('12');
        expect(text).toContain('Empresas activas');
        expect(text).toContain('Relaciones activas');
        expect(text).toContain('Afiliaciones activas');
        expect(text).toContain('Alertas de calidad');
    });

    it('draws no card for a figure the server withheld', async () => {
        // A role with nothing but the portfolio itself: every field is absent.
        vi.mocked(fetch).mockResolvedValue(jsonResponse(dashboard({})));

        const { wrapper, auth } = mountPage();

        auth.setUser({ permissions: ['portfolio.view'] } as AuthUser);

        await flushPromises();

        const text = wrapper.text();

        expect(text).not.toContain('Clientes activos');
        expect(text).not.toContain('Empresas activas');
        expect(text).not.toContain('Relaciones activas');
        expect(text).not.toContain('Afiliaciones activas');
        expect(text).not.toContain('Alertas de calidad');
    });

    it('does not report a withheld figure as zero', async () => {
        // The companies-only role: it may read companies, so that card appears, and
        // the client section is not for it to read, so no client card does.
        vi.mocked(fetch).mockResolvedValue(
            jsonResponse(
                dashboard({
                    active_companies: 7,
                    catalogue_entities: 9,
                }),
            ),
        );

        const { wrapper, auth } = mountPage();

        auth.setUser({ permissions: ['portfolio.view', 'companies.view'] } as AuthUser);

        await flushPromises();

        const text = wrapper.text();

        expect(text).toContain('Empresas activas');
        expect(text).toContain('7');
        expect(text).not.toContain('Clientes activos');
        expect(text).not.toContain('inactivos');
        expect(text).not.toContain('Relaciones activas');
        expect(text).not.toContain('Afiliaciones activas');
    });

    it('omits the inactive hint when only the active figure was sent', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(dashboard({ active_clients: 12 })));

        const { wrapper, auth } = mountPage();

        auth.setUser({ permissions: ['portfolio.view', 'clients.view'] } as AuthUser);

        await flushPromises();

        // The card is worth showing, and the hint is not: " inactivos" with nothing
        // in front of it would be a figure that does not exist.
        expect(wrapper.text()).toContain('Clientes activos');
        expect(wrapper.text()).not.toContain('inactivos');
    });
});