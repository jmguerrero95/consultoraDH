import { createPinia, setActivePinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import FloatingAssistant from '@/components/assistant/FloatingAssistant.vue';
import AsistentePage from '@/pages/Asistente/Index.vue';
import { groupArtifactsByMessage } from '@/services/ai';
import { useAuthStore } from '@/stores/auth';

/**
 * A07-R5-R3 (bloque D) — a generated file belongs beside its answer.
 *
 * The defect these tests pin: every file was piled at the end of the thread, so
 * after three more questions the PDF was nowhere near the answer that produced
 * it. The server now stamps the reply id on the file; these tests assert the
 * widget and the full page both honour it, and that a file an answer did *not*
 * produce (a background task, a proposal-only turn) stays in a collapsed section
 * rather than being passed off as part of the transcript.
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

type Handler = (url: string, init?: RequestInit) => Response;

function stubFetch(handler: Handler): ReturnType<typeof vi.fn> {
    const spy = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => handler(String(input), init));

    vi.stubGlobal('fetch', spy);

    return spy;
}

function mountWidget(): ReturnType<typeof mount> {
    const auth = useAuthStore();
    auth.setUser({
        id: 1,
        name: 'Ada',
        email: 'ada@example.test',
        primary_role: 'admin',
        account_type: 'staff',
        permissions: ['ai.use'],
    } as never);

    window.localStorage.setItem('cdh.assistant.open', '1');

    return mount(FloatingAssistant, { attachTo: document.body });
}

describe('archivos generados junto a su respuesta', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        window.localStorage.clear();
    });

    it('agrupa por mensaje y deja aparte los que no tienen respuesta', () => {
        const { byMessage, orphan } = groupArtifactsByMessage([
            { id: 1, filename: 'a.pdf', format: 'pdf', size_bytes: 1, download_url: '/a', expires_at: null, message_id: 7 },
            { id: 2, filename: 'b.xlsx', format: 'xlsx', size_bytes: 1, download_url: '/b', expires_at: null, message_id: 7 },
            { id: 3, filename: 'c.docx', format: 'docx', size_bytes: 1, download_url: '/c', expires_at: null, message_id: null },
        ]);

        expect(byMessage.get(7)?.map((artifact) => artifact.id)).toEqual([1, 2]);
        expect(orphan.map((artifact) => artifact.id)).toEqual([3]);
    });

    it('la página completa muestra el archivo bajo la respuesta que lo generó', async () => {
        stubFetch((url) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            if (url.includes('/api/ai/conversations/4')) {
                return jsonResponse({
                    conversation_id: 4,
                    messages: [
                        { id: 10, role: 'user', content: 'Genera el informe', provider: null, model: null, created_at: null },
                        { id: 11, role: 'assistant', content: 'Listo.', provider: 'p', model: 'm', created_at: null },
                        { id: 12, role: 'user', content: '¿Y ahora?', provider: null, model: null, created_at: null },
                        { id: 13, role: 'assistant', content: 'Nada más.', provider: 'p', model: 'm', created_at: null },
                    ],
                    artifacts: [
                        { id: 3, filename: 'informe-cartera.pdf', format: 'pdf', size_bytes: 2048, download_url: '/api/ai/artifacts/3/download', expires_at: null, message_id: 11 },
                        { id: 4, filename: 'resumen.docx', format: 'docx', size_bytes: 1024, download_url: '/api/ai/artifacts/4/download', expires_at: null, message_id: null },
                    ],
                });
            }

            return jsonResponse({ conversations: [{ id: 4, title: 'Informe', created_at: null }] });
        });

        const wrapper = mount(AsistentePage, { global: { stubs: { RouterLink: true } } });
        await flushPromises();

        const entry = wrapper.findAll('.cdh-ai__conversation').find((b) => b.text().includes('Informe'));
        await entry?.trigger('click');
        await flushPromises();

        // The report is under the reply (message 11), not at the foot of the page.
        const inline = wrapper.findAll('.cdh-ai__message-artifacts');
        expect(inline.length).toBe(1);
        expect(inline[0].text()).toContain('informe-cartera.pdf');

        // The file with no reply to sit beside is in the collapsed section.
        const collapsed = wrapper.find('details[data-testid="artifacts"]');
        expect(collapsed.exists()).toBe(true);
        expect(collapsed.text()).toContain('resumen.docx');
        expect(collapsed.text()).toContain('Archivos anteriores');
        // And it is *not* in the transcript.
        expect(inline[0].text()).not.toContain('resumen.docx');
    });

    it('la página completa pone el archivo bajo la respuesta recién recibida', async () => {
        stubFetch((url, init) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            if (url.includes('/api/ai/conversations') && init?.method === 'POST') {
                return jsonResponse({
                    conversation_id: 20,
                    reply: 'Listo, generé el informe.',
                    provider: 'p',
                    model: 'm',
                    proposals: [],
                    tools: [],
                    artifacts: [
                        { id: 3, filename: 'informe-cartera.pdf', format: 'pdf', size_bytes: 2048, download_url: '/api/ai/artifacts/3/download', expires_at: null },
                    ],
                });
            }

            return jsonResponse({ conversations: [] });
        });

        const wrapper = mount(AsistentePage, { global: { stubs: { RouterLink: true } } });
        await flushPromises();

        await wrapper.find('textarea').setValue('Genera el informe');
        await wrapper.find('form').trigger('submit.prevent');
        await flushPromises();

        const inline = wrapper.find('.cdh-ai__message-artifacts');
        expect(inline.exists()).toBe(true);
        expect(inline.text()).toContain('informe-cartera.pdf');
    });

    it('mantiene la posición de cada archivo en una conversación larga', async () => {
        // 20 messages, 8 of them assistant answers that each produced a file.
        // The defect was that every file drifted to the foot of the page; at this
        // size that is the difference between a transcript and a pile.
        // The shape is declared: without it TypeScript reads the first
        // element and decides `created_at` is `null` for the whole array, and
        // every later message with a real timestamp is a type error.
        const messages: {
            id: number;
            role: string;
            content: string;
            provider: string | null;
            model: string | null;
            created_at: string | null;
        }[] = [
            { id: 1, role: 'user', content: 'Hola', provider: null, model: null, created_at: null },
        ];
        const artifacts: Record<string, unknown>[] = [];

        let messageId = 1;
        let artifactId = 100;

        for (let turn = 0; turn < 20; turn += 1) {
            messageId += 1;
            messages.push({
                id: messageId,
                role: 'assistant',
                content: `Respuesta ${turn + 1}`,
                provider: 'p',
                model: 'm',
                created_at: null,
            });

            if (turn % 2 === 0) {
                artifactId += 1;
                artifacts.push({
                    id: artifactId,
                    filename: `archivo-${artifactId}.pdf`,
                    format: 'pdf',
                    size_bytes: 1024,
                    download_url: `/api/ai/artifacts/${artifactId}/download`,
                    expires_at: null,
                    message_id: messageId,
                });
            }
        }

        stubFetch((url) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse({ ai_enabled: false, state: 'disabled', available: false, active_model: null, cooldowns: {}, primary: { configured: false, model: null, base_url: null }, fallback: { configured: false, model: null, base_url: null } });
            }

            if (url.includes('/api/ai/tasks')) {
                return jsonResponse({
                    tasks: [
                        { id: 1, kind: 'document_ingest', status: 'succeeded', progress: 100, stage: null, name: 'a.csv', result: null, error: null, finished: true, conversation_id: 4 },
                        { id: 2, kind: 'document_ingest', status: 'succeeded', progress: 100, stage: null, name: 'b.csv', result: null, error: null, finished: true, conversation_id: 4 },
                        { id: 3, kind: 'document_ingest', status: 'succeeded', progress: 100, stage: null, name: 'c.csv', result: null, error: null, finished: true, conversation_id: 4 },
                        { id: 4, kind: 'document_ingest', status: 'succeeded', progress: 100, stage: null, name: 'd.csv', result: null, error: null, finished: true, conversation_id: 4 },
                        { id: 5, kind: 'document_ingest', status: 'succeeded', progress: 100, stage: null, name: 'e.csv', result: null, error: null, finished: true, conversation_id: 4 },
                    ],
                });
            }

            if (url.includes('/api/ai/conversations/4')) {
                return jsonResponse({ conversation_id: 4, messages, artifacts, attachments: [] });
            }

            return jsonResponse({ conversations: [{ id: 4, title: 'Larga', created_at: null }] });
        });

        const wrapper = mount(AsistentePage, { global: { stubs: { RouterLink: true } } });
        await flushPromises();

        await wrapper.findAll('.cdh-ai__conversation').find((b) => b.text().includes('Larga'))?.trigger('click');
        await flushPromises();

        // Each file sits under its own answer, and each appears exactly once.
        const blocks = wrapper.findAll('.cdh-ai__message-artifacts');
        expect(blocks.length).toBe(10);

        const links = wrapper.findAll('.cdh-ai__message-artifacts a');
        const hrefs = links.map((link) => link.attributes('href'));
        expect(hrefs.length).toBe(10);
        expect(new Set(hrefs).size).toBe(10);

        // Nothing was left to the foot of the page.
        expect(wrapper.find('details[data-testid="artifacts"]').exists()).toBe(false);
    });

    it('muestra el archivo como Seleccionado antes de que el servidor lo nombre', async () => {
        let release: (value: Response) => void = () => {};
        const pending = new Promise<Response>((resolve) => {
            release = resolve;
        });

        vi.stubGlobal(
            'fetch',
            vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
                const url = String(input);

                if (url.includes('/api/ai/status')) {
                    return jsonResponse(ENABLED_STATUS);
                }

                if (url.includes('/api/ai/conversations/open')) {
                    return jsonResponse({ conversation_id: 1, title: null, created_at: null }, 201);
                }

                if (url.includes('/api/ai/tasks') && init?.method === 'POST') {
                    return pending;
                }

                return jsonResponse({});
            }),
        );

        const wrapper = mountWidget();
        await flushPromises();

        const fileInput = wrapper.find('input[type="file"]');
        Object.defineProperty(fileInput.element, 'files', {
            value: [new File(['cliente,valor\nA,1\n'], 'cartera.csv', { type: 'text/csv' })],
        });
        await fileInput.trigger('change');
        await flushPromises();

        // The upload is still in flight: the chip says the file is selected and
        // names it, so the person sees their own action acknowledged.
        const chip = wrapper.find('.cdh-assistant__chip');
        expect(chip.text()).toContain('cartera.csv');
        expect(chip.text()).toContain('Seleccionado');

        // And nothing can be analysed from a file the server has not named yet.
        await wrapper.find('input[type="text"]').setValue('Analiza el CSV');
        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined();

        release(jsonResponse({ task: { id: 5, name: 'cartera.csv', status: 'queued', progress: 0, finished: false, error: null } }));
        await flushPromises();

        expect(wrapper.find('.cdh-assistant__chip').text()).toContain('Subiendo 0%');

        wrapper.unmount();
    });

    it('el widget muestra el archivo bajo el mensaje que lo generó', async () => {
        stubFetch((url, init) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            if (url.includes('/api/ai/conversations') && init?.method === 'POST') {
                return jsonResponse({
                    conversation_id: 21,
                    reply: 'Aquí está tu archivo.',
                    provider: 'p',
                    model: 'm',
                    proposals: [],
                    tools: [],
                    artifacts: [
                        { id: 9, filename: 'cartera.xlsx', format: 'xlsx', size_bytes: 512, download_url: '/api/ai/artifacts/9/download', expires_at: null },
                    ],
                });
            }

            return jsonResponse({ conversations: [] });
        });

        const wrapper = mountWidget();
        await flushPromises();

        await wrapper.find('input[type="text"]').setValue('Convierte el CSV');
        await wrapper.find('form').trigger('submit.prevent');
        await flushPromises();

        const inline = wrapper.find('.cdh-assistant__message-artifacts');

        expect(inline.exists()).toBe(true);
        expect(inline.text()).toContain('cartera.xlsx');

        wrapper.unmount();
    });

    it('el widget restaura los archivos por mensaje al reabrir el hilo', async () => {
        window.localStorage.setItem('cdh.assistant.conversation', '30');

        stubFetch((url) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            if (url.includes('/api/ai/conversations/30')) {
                return jsonResponse({
                    conversation_id: 30,
                    messages: [
                        { id: 1, role: 'user', content: 'Genera el PDF', provider: null, model: null, created_at: null },
                        { id: 2, role: 'assistant', content: 'Listo.', provider: 'p', model: 'm', created_at: null },
                    ],
                    artifacts: [
                        { id: 40, filename: 'informe.pdf', format: 'pdf', size_bytes: 100, download_url: '/api/ai/artifacts/40/download', expires_at: null, message_id: 2 },
                    ],
                    attachments: [],
                });
            }

            return jsonResponse({ conversations: [] });
        });

        const wrapper = mountWidget();
        await flushPromises();

        const inline = wrapper.find('.cdh-assistant__message-artifacts');

        expect(inline.exists()).toBe(true);
        expect(inline.text()).toContain('informe.pdf');
        // Nothing has no-reply files, so no collapsed section is drawn.
        expect(wrapper.find('.cdh-assistant__artifacts').exists()).toBe(false);

        wrapper.unmount();
    });

    it('el widget espera a que el adjunto termine y muestra su estado', async () => {
        stubFetch((url, init) => {
            if (url.includes('/api/ai/status')) {
                return jsonResponse(ENABLED_STATUS);
            }

            if (url.includes('/api/ai/conversations/open')) {
                return jsonResponse({ conversation_id: 1, title: 'Nueva', created_at: null }, 201);
            }

            if (url.includes('/api/ai/tasks/5')) {
                // Still being read: the turn must not analyse it yet.
                return jsonResponse({ task: { id: 5, name: 'cartera.csv', status: 'running', progress: 40, finished: false, error: null } });
            }

            if (url.includes('/api/ai/tasks') && init?.method === 'POST') {
                return jsonResponse({ task: { id: 5, name: 'cartera.csv', status: 'queued', progress: 0, finished: false, error: null } });
            }

            return jsonResponse({});
        });

        const wrapper = mountWidget();
        await flushPromises();

        const fileInput = wrapper.find('input[type="file"]');
        const file = new File(['cliente,valor\nA,1\n'], 'cartera.csv', { type: 'text/csv' });

        Object.defineProperty(fileInput.element, 'files', { value: [file] });
        await fileInput.trigger('change');
        await flushPromises();

        const chip = wrapper.find('.cdh-assistant__chip');

        expect(chip.exists()).toBe(true);
        expect(chip.text()).toContain('cartera.csv');
        expect(chip.text()).toContain('Procesando 40%');

        // With the file still ingesting, the question cannot be sent.
        await wrapper.find('input[type="text"]').setValue('Analiza el CSV');

        const submit = wrapper.find('button[type="submit"]');

        expect(submit.attributes('disabled')).toBeDefined();

        await wrapper.find('form').trigger('submit.prevent');
        await flushPromises();

        expect(wrapper.text()).toContain('Espera a que el archivo termine de procesarse');

        wrapper.unmount();
    });
});
