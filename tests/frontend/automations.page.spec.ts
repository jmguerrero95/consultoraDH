import { createPinia, setActivePinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AutomationPage from '@/pages/automations/AutomationPage.vue';
import { useAuthStore } from '@/stores/auth';
import type { AuthUser } from '@/types/api';

/**
 * The automations screen.
 *
 * The behaviours worth pinning here are the ones a screen can get wrong while
 * still looking right: a rule the viewer may not edit must not offer to edit it,
 * a schedule trigger must not send conditions the server refuses, and a failure
 * to load must be shown as a failure rather than as an empty list — "nothing is
 * configured" and "we could not ask" are different statements and only the first
 * is true.
 */

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

const VOCABULARY = {
    triggers: {
        schedule: {
            label: 'Programada',
            fields: {
                frequency: { type: 'string', label: 'Frecuencia', options: ['daily', 'weekly', 'monthly'] },
                time: { type: 'string', label: 'Hora' },
            },
        },
        audit_action: {
            label: 'Acción auditada',
            fields: { action_name: { type: 'string', label: 'Nombre de la acción' } },
        },
    },
    conditions: {},
    actions: {
        internal_notification: {
            label: 'Notificación interna',
            config: {
                title: { type: 'string', label: 'Título' },
                message: { type: 'string', label: 'Mensaje' },
            },
        },
    },
};

function signIn(permissions: string[]): void {
    useAuthStore().setUser({
        id: 1,
        name: 'Administrador',
        account_type: 'staff',
        primary_role: 'Super Admin',
        permissions,
    } as unknown as AuthUser);
}

function rule(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id: 10,
        name: 'Cobro vencido',
        description: 'Avisa cuando un recibo pasa a vencido',
        trigger_type: 'receivable_overdue',
        trigger_config: { min_days_overdue: 1 },
        condition_config: [],
        owner_user_id: 1,
        active: true,
        revision: 1,
        next_run_at: '2026-10-11T12:00:00+00:00',
        last_run_at: null,
        created_at: null,
        updated_at: null,
        actions: [
            { id: 100, automation_rule_id: 10, position: 0, action_type: 'internal_notification', label: '', config: {}, active: true },
        ],
        ...overrides,
    };
}

function stubFetch(handler: (url: string, init?: RequestInit) => Response): ReturnType<typeof vi.fn> {
    const spy = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) =>
        handler(String(input), init),
    );

    vi.stubGlobal('fetch', spy);

    return spy as unknown as ReturnType<typeof vi.fn>;
}

function routes(url: string): boolean {
    if (url.includes('/api/automations/vocabulary')) {
        return true;
    }

    return url.includes('/api/automations');
}

async function mountPage(): Promise<ReturnType<typeof mount>> {
    const wrapper = mount(AutomationPage, {
        global: { stubs: { RouterLink: true } },
    });

    await flushPromises();
    await vi.waitFor(() => expect(wrapper.text()).not.toContain('Cargando automatizaciones'));

    return wrapper;
}

describe('automatizaciones', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.restoreAllMocks();
    });

    it('lists the rules the server returned', async () => {
        signIn(['automations.view', 'automations.manage']);

        stubFetch((url) => {
            if (!routes(url)) {
                return jsonResponse({}, 404);
            }

            if (url.includes('/api/automations/vocabulary')) {
                return jsonResponse(VOCABULARY);
            }

            return jsonResponse({
                data: [rule()],
                current_page: 1,
                last_page: 1,
                per_page: 25,
                total: 1,
            });
        });

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Automatizaciones');
        expect(wrapper.text()).toContain('Cobro vencido');
        expect(wrapper.text()).toContain('Notificación interna');
    });

    it('hides the management actions from a role that may only read', async () => {
        signIn(['automations.view']);

        stubFetch((url) =>
            url.includes('/api/automations/vocabulary')
                ? jsonResponse(VOCABULARY)
                : jsonResponse({ data: [rule()], current_page: 1, last_page: 1, per_page: 25, total: 1 }),
        );

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Consulta de solo lectura');

        // Asserted on the controls themselves: the form lives in a dialog that is
        // present but closed, so its title is in the rendered text either way.
        const labels = wrapper.findAll('button').map((button) => button.text());

        expect(labels).not.toContain('Nueva regla');
        expect(labels).not.toContain('Desactivar');
        expect(labels).not.toContain('Editar');
    });

    it('says a load failed instead of showing an empty list', async () => {
        signIn(['automations.view']);

        stubFetch((url) =>
            url.includes('/api/automations/vocabulary')
                ? jsonResponse(VOCABULARY)
                : jsonResponse({ message: 'No fue posible cargar las automatizaciones.' }, 500),
        );

        const wrapper = await mountPage();

        // "There was an error" must not read as "nothing is configured".
        expect(wrapper.text()).toContain('No fue posible cargar las automatizaciones.');
        expect(wrapper.text()).not.toContain('No hay automatizaciones configuradas');
    });

    it('disables the edit control on an active rule, because the server refuses that edit', async () => {
        signIn(['automations.view', 'automations.manage']);

        stubFetch((url) =>
            url.includes('/api/automations/vocabulary')
                ? jsonResponse(VOCABULARY)
                : jsonResponse({ data: [rule({ active: true })], current_page: 1, last_page: 1, per_page: 25, total: 1 }),
        );

        const wrapper = await mountPage();

        const editButton = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Editar');

        expect(editButton).toBeDefined();
        expect(editButton?.attributes('disabled')).toBeDefined();
    });

    it('offers the edit control on a deactivated rule', async () => {
        signIn(['automations.view', 'automations.manage']);

        stubFetch((url) =>
            url.includes('/api/automations/vocabulary')
                ? jsonResponse(VOCABULARY)
                : jsonResponse({ data: [rule({ active: false })], current_page: 1, last_page: 1, per_page: 25, total: 1 }),
        );

        const wrapper = await mountPage();

        const editButton = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Editar');

        expect(editButton).toBeDefined();
        expect(editButton?.attributes('disabled')).toBeUndefined();
    });

    it('deactivates through the endpoint the server exposes, not a generic update', async () => {
        signIn(['automations.view', 'automations.manage']);

        const spy = stubFetch((url, init) => {
            if (init?.method === 'POST') {
                return jsonResponse({ message: 'Regla desactivada', rule: rule({ active: false }) });
            }

            if (url.includes('/api/automations/vocabulary')) {
                return jsonResponse(VOCABULARY);
            }

            return jsonResponse({ data: [rule()], current_page: 1, last_page: 1, per_page: 25, total: 1 });
        });

        const wrapper = await mountPage();

        const toggle = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Desactivar');

        await toggle?.trigger('click');
        await flushPromises();

        const deactivate = spy.mock.calls.find(
            (call) => String(call[0]).includes('/deactivate') && call[1]?.method === 'POST',
        );

        expect(deactivate).toBeDefined();
    });
});