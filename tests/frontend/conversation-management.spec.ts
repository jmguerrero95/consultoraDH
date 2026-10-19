import { createPinia, setActivePinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AsistentePage from '@/pages/Asistente/Index.vue';
import { useAuthStore } from '@/stores/auth';

/**
 * Conversation management and the unsent draft — the two pieces of the assistant
 * screen a person notices when they are missing.
 *
 * The defects pinned here: a thread could not be renamed or removed from the
 * screen, and a half-written question was thrown away by a reload. Neither is
 * cosmetic — an assistant you cannot tidy up, and one that loses what you typed,
 * is an assistant you stop using.
 */

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

const ENABLED_STATUS = {
    ai_enabled: true,
    state: 'available',
    available: true,
    active_model: 'llama-3.3-free',
    cooldowns: {},
    independent_providers: 2,
    primary: { configured: true, model: 'llama-3.3-free' },
    fallback: { configured: false, model: null },
    timeout_seconds: 30,
    proposal_ttl_minutes: 15,
};

type Handler = (url: string, init?: RequestInit) => Response;

function stubFetch(handler: Handler): ReturnType<typeof vi.fn> {
    const spy = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => handler(String(input), init));

    vi.stubGlobal('fetch', spy);

    return spy;
}

function signIn(): void {
    useAuthStore().setUser({
        id: 1,
        name: 'Ada',
        email: 'ada@example.test',
        primary_role: 'admin',
        account_type: 'staff',
        permissions: ['ai.use'],
    } as never);
}

async function mountPage(): Promise<ReturnType<typeof mount>> {
    const wrapper = mount(AsistentePage, { global: { stubs: { RouterLink: true } } });

    await flushPromises();

    return wrapper;
}

describe('gestión de conversaciones', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        window.sessionStorage.clear();
        vi.restoreAllMocks();
    });

    it('renombra una conversación y muestra el nuevo título', async () => {
        const spy = stubFetch((url, init) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            if (url.includes('/api/ai/conversations/5') && init?.method === 'PATCH') {
                return jsonResponse({ conversation_id: 5, title: 'Cartera de octubre' });
            }

            return jsonResponse({ conversations: [{ id: 5, title: 'Cartera', created_at: null }] });
        });

        const wrapper = await mountPage();

        await wrapper.find('[data-test="conversation-rename"]').trigger('click');

        const input = wrapper.find('[data-test="conversation-title-input"]');
        expect(input.exists()).toBe(true);

        await input.setValue('Cartera de octubre');
        await wrapper.find('form.cdh-ai__conversation-edit').trigger('submit.prevent');
        await flushPromises();

        const renamed = spy.mock.calls.find(
            (call) => String(call[0]).includes('/api/ai/conversations/5') && (call[1] as RequestInit | undefined)?.method === 'PATCH',
        );
        expect(renamed).toBeDefined();
        expect(wrapper.text()).toContain('Cartera de octubre');
    });

    it('no renombra con un título vacío', async () => {
        const spy = stubFetch((url) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            return jsonResponse({ conversations: [{ id: 5, title: 'Cartera', created_at: null }] });
        });

        const wrapper = await mountPage();

        await wrapper.find('[data-test="conversation-rename"]').trigger('click');
        await wrapper.find('[data-test="conversation-title-input"]').setValue('   ');
        await wrapper.find('form.cdh-ai__conversation-edit').trigger('submit.prevent');
        await flushPromises();

        expect(
            spy.mock.calls.some((call) => (call[1] as RequestInit | undefined)?.method === 'PATCH'),
        ).toBe(false);
        expect(wrapper.text()).toContain('El título no puede estar vacío.');
    });

    it('elimina una conversación tras confirmarlo y la quita de la lista', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        const spy = stubFetch((url, init) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            if (url.includes('/api/ai/conversations/5') && init?.method === 'DELETE') {
                return jsonResponse({ deleted: true, conversation_id: 5 });
            }

            return jsonResponse({ conversations: [{ id: 5, title: 'Cartera', created_at: null }] });
        });

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Cartera');

        await wrapper.find('[data-test="conversation-delete"]').trigger('click');
        await flushPromises();

        expect(
            spy.mock.calls.some(
                (call) => String(call[0]).includes('/api/ai/conversations/5') && (call[1] as RequestInit | undefined)?.method === 'DELETE',
            ),
        ).toBe(true);
        expect(wrapper.text()).not.toContain('Cartera');
    });

    it('no elimina nada si el usuario cancela la confirmación', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);

        const spy = stubFetch((url) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            return jsonResponse({ conversations: [{ id: 5, title: 'Cartera', created_at: null }] });
        });

        const wrapper = await mountPage();

        await wrapper.find('[data-test="conversation-delete"]').trigger('click');
        await flushPromises();

        expect(
            spy.mock.calls.some((call) => (call[1] as RequestInit | undefined)?.method === 'DELETE'),
        ).toBe(false);
        expect(wrapper.text()).toContain('Cartera');
    });
});

describe('borrador no enviado', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        window.sessionStorage.clear();
        vi.restoreAllMocks();
    });

    it('conserva el texto escrito a través de una recarga', async () => {
        signIn();

        stubFetch((url) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            return jsonResponse({ conversations: [] });
        });

        const first = await mountPage();
        await first.find('textarea').setValue('¿Cuánto se debe de cartera?');
        await flushPromises();
        first.unmount();

        // A reload: the page is built again from scratch against the same session.
        const second = await mountPage();
        const restored = second.find('textarea').element as HTMLTextAreaElement;

        expect(restored.value).toBe('¿Cuánto se debe de cartera?');
    });

    it('no filtra el borrador de otro usuario', async () => {
        signIn();

        stubFetch((url) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            return jsonResponse({ conversations: [] });
        });

        const first = await mountPage();
        await first.find('textarea').setValue('Texto de Ada');
        await flushPromises();
        first.unmount();

        // A different account on the same browser must start empty.
        useAuthStore().setUser({
            id: 2,
            name: 'Otro',
            email: 'otro@example.test',
            primary_role: 'staff',
            account_type: 'staff',
            permissions: ['ai.use'],
        } as never);

        const second = await mountPage();

        expect((second.find('textarea').element as HTMLTextAreaElement).value).toBe('');
    });
});

describe('disponibilidad por operación', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        window.sessionStorage.clear();
        vi.restoreAllMocks();
    });

    it('explica la operación limitada en lugar de decir siempre "sin comprobar"', async () => {
        stubFetch((url) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse({
                    ...ENABLED_STATUS,
                    state: 'unchecked',
                    capabilities: {
                        general_chat: { label: 'Conversación general', state: 'unverified', available: true, eligible: 2, ready: 2, independent_providers: 2, preferred_model: 'm', last_success: null, retry_after_seconds: null, reason: null },
                        internal_data: { label: 'Consulta de datos internos', state: 'blocked', available: false, eligible: 1, ready: 0, independent_providers: 0, preferred_model: null, last_success: null, retry_after_seconds: null, reason: 'La consulta de datos internos está limitada en este momento.' },
                    },
                });
            }

            return jsonResponse({ conversations: [] });
        });

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('La consulta de datos internos está limitada en este momento.');
        expect(wrapper.text()).not.toContain('Disponibilidad del servicio sin comprobar todavía.');
    });
});
