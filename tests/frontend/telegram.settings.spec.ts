import { createPinia, setActivePinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import TelegramSettingsPage from '@/pages/settings/TelegramSettingsPage.vue';

/**
 * The Telegram destinations screen.
 *
 * The properties pinned here are the ones that decide where a client's
 * conversation may be announced: a destination that is bound to a conversation
 * says so on the row, and turning one off stops it being contacted rather than
 * only being dimmed. A failure to load is also asserted separately from an empty
 * list, because the two are different facts.
 */

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function endpoint(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id: 1,
        label: 'SLA de soporte',
        chat_id: '-1001234567890',
        conversation_id: null,
        enabled: true,
        event_preferences: ['support_sla_breach'],
        created_by: 1,
        created_at: null,
        updated_at: null,
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

async function mountPage(): Promise<ReturnType<typeof mount>> {
    const wrapper = mount(TelegramSettingsPage, { global: { stubs: { RouterLink: true } } });

    await flushPromises();
    await vi.waitFor(() => expect(wrapper.text()).not.toContain('Cargando destinos'));

    return wrapper;
}

describe('notificaciones por Telegram', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.restoreAllMocks();
    });

    it('lists the destinations the server returned', async () => {
        stubFetch(() => jsonResponse([endpoint()]));

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Notificaciones por Telegram');
        expect(wrapper.text()).toContain('SLA de soporte');
        expect(wrapper.text()).toContain('-1001234567890');
    });

    it('states that a destination with no conversation covers every chat', async () => {
        stubFetch(() => jsonResponse([endpoint()]));

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Todos los chats');
    });

    it('states the conversation a scoped destination belongs to', async () => {
        stubFetch(() => jsonResponse([endpoint({ conversation_id: 77 })]));

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Conversación #77');
        expect(wrapper.text()).not.toContain('Todos los chats');
    });

    it('offers an empty state that is about configuration, not an error', async () => {
        stubFetch(() => jsonResponse([]));

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('No hay destinos de Telegram');
    });

    it('says a load failed instead of showing an empty state', async () => {
        stubFetch(() => jsonResponse({ message: 'No fue posible cargar los destinos.' }, 500));

        const wrapper = await mountPage();

        // The failure must not be reported as "you have none configured".
        expect(wrapper.text()).toContain('No fue posible cargar los destinos.');
        expect(wrapper.text()).not.toContain('No hay destinos de Telegram');
    });

    it('deactivates a destination through a PATCH carrying the inverted flag', async () => {
        const spy = stubFetch((url, init) => {
            if (init?.method === 'PATCH') {
                return jsonResponse(endpoint({ enabled: false }));
            }

            return jsonResponse([endpoint()]);
        });

        const wrapper = await mountPage();

        const toggle = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Desactivar');

        await toggle?.trigger('click');
        await flushPromises();

        const patch = spy.mock.calls.find((call) => call[1]?.method === 'PATCH');

        expect(patch).toBeDefined();
        expect(String(patch?.[1]?.body)).toContain('"enabled":false');
    });

    it('sends the test message through the endpoint the server exposes', async () => {
        const spy = stubFetch((url, init) => {
            if (url.includes('/test') && init?.method === 'POST') {
                return jsonResponse({ message: 'Mensaje de prueba encolado' });
            }

            return jsonResponse([endpoint()]);
        });

        const wrapper = await mountPage();

        const test = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Enviar prueba');

        await test?.trigger('click');
        await flushPromises();

        expect(spy.mock.calls.some((call) => String(call[0]).includes('/1/test'))).toBe(true);
    });
});