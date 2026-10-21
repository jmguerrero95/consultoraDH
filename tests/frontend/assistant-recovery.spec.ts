import { createPinia, setActivePinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AsistentePage from '@/pages/Asistente/Index.vue';

/**
 * G08: the recovery window the assistant announces.
 *
 * The server reports the earliest moment some eligible provider comes back and
 * the worst case as the other end of the range. The screen has to show both
 * when they differ: the old countdown took the worst case and told the person
 * to wait for a provider that would have been available minutes earlier, and a
 * single number would be a promise the server cannot keep.
 */
function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function statusWithRecovery(earliest: number, latest: number): Record<string, unknown> {
    return {
        ai_enabled: true,
        state: 'quota_limited',
        available: false,
        active_model: null,
        cooldowns: {},
        primary: { configured: true, model: 'modelo-uno' },
        fallback: { configured: false, model: null },
        timeout_seconds: 30,
        proposal_ttl_minutes: 15,
        independent_providers: 2,
        capabilities: {
            internal_data: {
                label: 'Consulta de datos internos',
                state: 'quota_limited',
                available: false,
                eligible: 2,
                ready: 0,
                independent_providers: 2,
                preferred_model: null,
                last_success: null,
                retry_after_seconds: earliest,
                retry_after_max_seconds: latest,
                reason: 'La cuota de los proveedores elegibles está agotada temporalmente.',
            },
        },
    };
}

function stubStatus(status: Record<string, unknown>): void {
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
        const url = String(input);

        if (url.includes('/api/ai/status')) {
            return jsonResponse(status);
        }

        return jsonResponse({ conversations: [] });
    }));
}

async function mountPage(): Promise<ReturnType<typeof mount>> {
    const wrapper = mount(AsistentePage, { global: { stubs: { RouterLink: true } } });

    await flushPromises();

    return wrapper;
}

describe('ventana de recuperación del asistente', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        window.localStorage.clear();
    });

    it('muestra el rango cuando los proveedores vuelven en momentos distintos', async () => {
        stubStatus(statusWithRecovery(60, 3600));

        const wrapper = await mountPage();

        const text = wrapper.text();

        expect(text).toContain('Vuelve a estar disponible entre 1 min y 1 h.');
    });

    it('muestra un único plazo cuando todos vuelven a la vez', async () => {
        stubStatus(statusWithRecovery(120, 120));

        const wrapper = await mountPage();

        const text = wrapper.text();

        expect(text).toContain('Vuelve a estar disponible en 2 min.');
        expect(text).not.toContain('entre 2 min');
    });
});
