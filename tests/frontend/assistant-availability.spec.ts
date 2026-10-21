import { createPinia, setActivePinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import FloatingAssistant from '@/components/assistant/FloatingAssistant.vue';
import { useAuthStore } from '@/stores/auth';

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function statusWith(internalState: string, state = 'unchecked'): Record<string, unknown> {
    return {
        ai_enabled: true,
        state,
        available: true,
        active_model: 'modelo-uno',
        cooldowns: {},
        primary: { configured: true, model: 'modelo-uno' },
        fallback: { configured: false, model: null },
        timeout_seconds: 30,
        proposal_ttl_minutes: 15,
        capabilities: {
            internal_data: {
                label: 'Consulta de datos internos',
                state: internalState,
                available: internalState === 'available' || internalState === 'degraded',
                eligible: 2,
                ready: 2,
                independent_providers: 2,
                preferred_model: 'modelo-uno',
                last_success: null,
                retry_after_seconds: null,
                reason: null,
            },
        },
    };
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

describe('estado de disponibilidad del asistente', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        window.localStorage.clear();
    });

    it('no llama "limitadas" a operaciones sin evidencia reciente', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(statusWith('unverified'))));

        const wrapper = mountWidget();
        await flushPromises();

        const notice = wrapper.find('.cdh-assistant__notice');
        expect(notice.exists()).toBe(true);
        expect(notice.text()).toContain('Todavía no hay evidencia reciente');
        expect(notice.text()).toContain('no significa que los proveedores estén caídos');
        expect(notice.text()).not.toContain('limitadas');
        expect(wrapper.find('.cdh-assistant__status').text()).toBe('Listo · sin verificar');
    });

    it('distingue una cuota agotada de una falta de evidencia', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(statusWith('quota_limited'))));

        const wrapper = mountWidget();
        await flushPromises();

        expect(wrapper.find('.cdh-assistant__notice').text()).toContain('cuota');
        expect(wrapper.find('.cdh-assistant__status').text()).toBe('Límite en consultas internas');
    });

    it('refresca el estado después de un turno', async () => {
        let statusCalls = 0;

        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);

            if (url.includes('/api/ai/status')) {
                statusCalls += 1;

                return jsonResponse(statusWith('unverified'));
            }

            if (url.includes('/api/ai/conversations')) {
                return jsonResponse({
                    conversation_id: 7,
                    reply: 'Respuesta de prueba.',
                    proposals: [],
                    artifacts: [],
                    tools: [],
                });
            }

            return jsonResponse({});
        }));

        const wrapper = mountWidget();
        await flushPromises();

        const initialCalls = statusCalls;

        await wrapper.find('input[aria-label="Pregunta para el asistente"]').setValue('Hola');
        await wrapper.find('form').trigger('submit.prevent');
        await flushPromises();

        expect(statusCalls).toBeGreaterThan(initialCalls);
    });
});
