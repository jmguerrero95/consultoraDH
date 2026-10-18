import { createPinia, setActivePinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AsistentePage from '@/pages/Asistente/Index.vue';

/**
 * The assistant screen.
 *
 * These assertions target the defects that made the A07 page non-functional
 * rather than its styling: it imported a composable that does not exist, it read
 * `.data` from a body that is already unwrapped, and it rendered tool results as
 * if they were citations. Each test below fails if one of those returns.
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
    primary: { configured: true, model: 'llama-3.3-free', base_url: 'https://provider.example' },
    fallback: { configured: false, model: null, base_url: null },
    timeout_seconds: 30,
    proposal_ttl_minutes: 15,
};

/** The calls the page issued, so a test can assert what was and was not sent. */
type FetchSpy = { mock: { calls: [RequestInfo | URL, RequestInit?][] } };

function stubFetch(handler: (url: string, init?: RequestInit) => Response): FetchSpy {
    const spy = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => handler(String(input), init));

    vi.stubGlobal('fetch', spy);

    return spy as unknown as FetchSpy;
}

/** Answer the two calls the page makes on mount, and nothing else. */
function stubShell(overrides: { conversations?: unknown[] } = {}): FetchSpy {
    return stubFetch((url, init) => {
        if (url.includes('/api/ai/status')) {
            return jsonResponse(ENABLED_STATUS);
        }

        if (url.includes('/api/ai/conversations')) {
            return jsonResponse({ conversations: overrides.conversations ?? [] });
        }

        return jsonResponse({}, init?.method === 'POST' ? 200 : 404);
    });
}

async function mountPage(): Promise<ReturnType<typeof mount>> {
    const wrapper = mount(AsistentePage, { global: { stubs: { RouterLink: true } } });

    await flushPromises();

    return wrapper;
}

describe('asistente de IA', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    it('informa el modelo configurado sin exponer credenciales', async () => {
        stubShell();
        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Asistente de IA');
        expect(wrapper.text()).toContain('llama-3.3-free');
        // The status endpoint returns a base URL and booleans only; no key may
        // ever reach the DOM.
        expect(wrapper.text()).not.toContain('api_key');
    });

    it('avisa cuando no hay proveedor configurado en lugar de fallar en silencio', async () => {
        stubFetch((url) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse({
                    ai_enabled: true,
                    state: 'none',
                    available: false,
                    active_model: null,
                    cooldowns: {},
                    primary: { configured: false, model: null, base_url: null },
                    fallback: { configured: false, model: null, base_url: null },
                    timeout_seconds: 30,
                    proposal_ttl_minutes: 15,
                });
            }

            return jsonResponse({ conversations: [] });
        });

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('No hay ningún proveedor de IA configurado.');
    });

    it('deshabilita el envío mientras no hay consulta', async () => {
        stubShell();
        const wrapper = await mountPage();

        const button = wrapper.find('button[type="submit"]');

        expect(button.attributes('disabled')).toBeDefined();
    });

    it('envía la consulta y muestra la respuesta del asistente', async () => {
        stubFetch((url, init) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            if (url.includes('/api/ai/conversations') && init?.method === 'POST') {
                return jsonResponse({
                    conversation_id: 7,
                    reply: 'Tienes 3 cartera vencida por $4.200.000.',
                    provider: 'openai-compatible',
                    model: 'llama-3.3-free',
                    proposals: [],
                    tools: [],
                });
            }

            return jsonResponse({ conversations: [{ id: 7, title: 'Cartera', created_at: '2026-10-11T10:00:00' }] });
        });

        const wrapper = await mountPage();

        await wrapper.find('textarea').setValue('¿Qué cartera está vencida?');
        await wrapper.find('form').trigger('submit.prevent');
        await flushPromises();

        // The reply arrives unwrapped. Reading `.data.reply` here is exactly the
        // bug that left this page blank with no error logged.
        expect(wrapper.text()).toContain('Tienes 3 cartera vencida');
        expect(wrapper.text()).toContain('¿Qué cartera está vencida?');
    });

    it('convierte resultados de búsqueda web en citas con enlace real', async () => {
        stubFetch((url, init) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            if (url.includes('/api/ai/conversations') && init?.method === 'POST') {
                return jsonResponse({
                    conversation_id: 9,
                    reply: 'Según Banco de la República…',
                    provider: 'openai-compatible',
                    model: 'llama-3.3-free',
                    proposals: [],
                    tools: [
                        {
                            tool: 'web_search',
                            output: {
                                query: 'Colombia economia',
                                results: [
                                    {
                                        title: 'Inflación mensual',
                                        url: 'https://www.banrep.gov.co/inflacion',
                                        domain: 'banrep.gov.co',
                                        snippet: '…',
                                        published_at: null,
                                    },
                                ],
                            },
                        },
                    ],
                });
            }

            return jsonResponse({ conversations: [] });
        });

        const wrapper = await mountPage();

        await wrapper.find('textarea').setValue('¿Cómo va la inflación?');
        await wrapper.find('form').trigger('submit.prevent');
        await flushPromises();

        const link = wrapper.findAll('a').find((a) => a.attributes('href') === 'https://www.banrep.gov.co/inflacion');

        expect(link).toBeDefined();
        expect(link?.text()).toContain('Inflación mensual');
        expect(wrapper.text()).toContain('banrep.gov.co');
    });

    it('no inventa citas a partir de resultados que no son búsqueda web', async () => {
        stubFetch((url, init) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            if (url.includes('/api/ai/conversations') && init?.method === 'POST') {
                return jsonResponse({
                    conversation_id: 10,
                    reply: 'Tienes 1 cliente activo.',
                    provider: 'openai-compatible',
                    model: 'llama-3.3-free',
                    proposals: [],
                    tools: [{ tool: 'client_summary', output: { total: 1, url: 'https://internal.invalid/clientes' } }],
                });
            }

            return jsonResponse({ conversations: [] });
        });

        const wrapper = await mountPage();

        await wrapper.find('textarea').setValue('¿Cuántos clientes?');
        await wrapper.find('form').trigger('submit.prevent');
        await flushPromises();

        // Internal tool output is data, not a citable source.
        expect(wrapper.text()).not.toContain('Fuentes');
        expect(wrapper.text()).not.toContain('https://internal.invalid/clientes');
    });

    it('presenta una propuesta como pendiente y la confirma solo al pedirlo', async () => {
        const proposal = {
            id: 42,
            tool: 'create_operational_task',
            arguments: { title: 'Revisar cartera' },
            expires_at: '2026-10-11T11:00:00',
            pending: true,
        };

        const spy = stubFetch((url, init) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            if (url.includes('/confirm')) {
                return jsonResponse({ confirmed: true, proposal_id: 42, tool: 'create_operational_task', result: { message: 'Tarea creada.' } });
            }

            // Confirming refreshes the transcript, so the history call has to
            // answer for real rather than fall through to the 404 branch.
            if (url.includes('/api/ai/conversations/11')) {
                return jsonResponse({ conversation_id: 11, messages: [] });
            }

            if (url.includes('/api/ai/conversations') && init?.method === 'POST') {
                return jsonResponse({
                    conversation_id: 11,
                    reply: 'Propuse crear la tarea.',
                    provider: 'openai-compatible',
                    model: 'llama-3.3-free',
                    proposals: [proposal],
                    tools: [],
                });
            }

            return jsonResponse({ conversations: [] });
        });

        const wrapper = await mountPage();

        await wrapper.find('textarea').setValue('Crea una tarea');
        await wrapper.find('form').trigger('submit.prevent');
        await flushPromises();

        expect(wrapper.text()).toContain('create_operational_task');
        // Nothing has run yet: the proposal is only stored.
        expect(spy.mock.calls.some((call) => String(call[0]).includes('/confirm'))).toBe(false);

        const confirm = wrapper.findAll('button').find((b) => b.text() === 'Confirmar');
        await confirm?.trigger('click');
        await flushPromises();

        expect(spy.mock.calls.some((call) => String(call[0]).includes('/api/ai/action-proposals/42/confirm'))).toBe(true);
    });

    it('conserva la consulta escrita cuando el servidor falla', async () => {
        stubFetch((url, init) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            if (init?.method === 'POST') {
                // The controller answers under both keys; the shared HTTP client
                // reads `message`, so a body without it would be discarded and
                // the real reason never reach the screen.
                return jsonResponse({ error: 'El proveedor no está disponible.', message: 'El proveedor no está disponible.' }, 503);
            }

            return jsonResponse({ conversations: [] });
        });

        const wrapper = await mountPage();

        await wrapper.find('textarea').setValue('¿Y ahora?');
        await wrapper.find('form').trigger('submit.prevent');
        await flushPromises();

        expect(wrapper.text()).toContain('El proveedor no está disponible.');
        // Losing the typed text on the one failure the user can retry is the
        // worst possible moment to discard it.
        expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('¿Y ahora?');
    });

    it('lista las conversaciones y abre la seleccionada', async () => {
        const spy = stubFetch((url) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            if (url.includes('/api/ai/conversations/5')) {
                return jsonResponse({
                    conversation_id: 5,
                    messages: [
                        {
                            role: 'user',
                            content: 'Pregunta anterior',
                            provider: null,
                            model: null,
                            created_at: '2026-10-11T09:00:00',
                        },
                    ],
                });
            }

            return jsonResponse({ conversations: [{ id: 5, title: 'Informe mensual', created_at: '2026-10-11T09:00:00' }] });
        });

        const wrapper = await mountPage();

        expect(wrapper.text()).toContain('Informe mensual');

        const entry = wrapper.findAll('.cdh-ai__conversation').find((b) => b.text().includes('Informe mensual'));
        await entry?.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Pregunta anterior');
        expect(spy.mock.calls.some((call) => String(call[0]).includes('/api/ai/conversations/5'))).toBe(true);
    });

    it('muestra el archivo generado con su enlace de descarga', async () => {
        stubFetch((url, init) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            if (url.includes('/api/ai/conversations') && init?.method === 'POST') {
                return jsonResponse({
                    conversation_id: 15,
                    reply: 'Listo, generé el informe.',
                    provider: 'openai-compatible',
                    model: 'llama-3.3-free',
                    proposals: [],
                    tools: [],
                    artifacts: [
                        {
                            id: 3,
                            filename: 'informe-cartera.pdf',
                            format: 'pdf',
                            size_bytes: 2048,
                            download_url: '/api/ai/artifacts/3/download',
                            expires_at: null,
                        },
                    ],
                });
            }

            return jsonResponse({ conversations: [] });
        });

        const wrapper = await mountPage();

        await wrapper.find('textarea').setValue('Genera el informe');
        await wrapper.find('form').trigger('submit.prevent');
        await flushPromises();

        const link = wrapper.findAll('a').find((a) => a.attributes('href') === '/api/ai/artifacts/3/download');

        expect(link).toBeDefined();
        expect(link?.text()).toContain('informe-cartera.pdf');
    });

    it('muestra en verde el resultado de la acción confirmada', async () => {
        const proposal = {
            id: 51,
            tool: 'create_operational_task',
            arguments: { title: 'Revisar cartera' },
            expires_at: '2026-10-11T11:00:00',
            pending: true,
        };

        stubFetch((url, init) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            if (url.includes('/confirm')) {
                return jsonResponse({
                    confirmed: true,
                    proposal_id: 51,
                    tool: 'create_operational_task',
                    result: { message: 'Tarea creada.' },
                });
            }

            if (url.includes('/api/ai/conversations/12')) {
                return jsonResponse({ conversation_id: 12, messages: [] });
            }

            if (url.includes('/api/ai/conversations') && init?.method === 'POST') {
                return jsonResponse({
                    conversation_id: 12,
                    reply: 'Propuse crear la tarea.',
                    provider: 'openai-compatible',
                    model: 'llama-3.3-free',
                    proposals: [proposal],
                    tools: [],
                });
            }

            return jsonResponse({ conversations: [] });
        });

        const wrapper = await mountPage();

        await wrapper.find('textarea').setValue('Crea una tarea');
        await wrapper.find('form').trigger('submit.prevent');
        await flushPromises();

        const confirm = wrapper.findAll('button').find((b) => b.text() === 'Confirmar');
        await confirm?.trigger('click');
        await flushPromises();
        await nextTick();

        const confirmationEl = wrapper.find('[data-testid="confirmation"]');
        expect(confirmationEl.exists()).toBe(true);
        expect(confirmationEl.text()).toContain('Tarea creada.');
    });
});
