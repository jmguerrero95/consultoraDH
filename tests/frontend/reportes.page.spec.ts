import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';

import ReportesPage from '@/pages/reportes/ReportesPage.vue';
import { useAuthStore } from '@/stores/auth';

import { resetCsrfState } from '@/services/http';
import type { AuthUser } from '@/types/api';

/** A user with exactly the permissions a test names, and no others. */
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

vi.mock('@/services/a05', () => ({
    a05: {
        reports: {
            vocabulary: async () => ({
                types: [
                    { value: 'morosos', label: 'Morosos / cartera pendiente' },
                    { value: 'libro_operativo', label: 'Libro operativo (formato original)' },
                ],
                formats: [{ value: 'xlsx', label: 'XLSX' }],
            }),
            // The shape the existing results table reads: `meta.filters` is an array and the
            // template does `filters.length`, so a partial mock is a render error, not a warning.
            run: async () => ({
                columns: [],
                rows: [],
                meta: { title: 'Morosos', as_of: '2026-03-10', generated_at: '2026-03-10', filters: [] },
            }),
        },
    },
}));

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

/**
 * Mount the page and record every download it asks the server for.
 *
 * Downloads now go through `fetch` so the screen can show progress and surface a
 * real error. The URL is observed at the request, not at the anchor: the anchor
 * only receives a blob URL, while the filters live on the request.
 */
async function mounted(permissions = ['reports.view', 'reports.export']) {
    const downloads: string[] = [];

    setActivePinia(createPinia());
    useAuthStore().setUser(user(permissions));

    vi.stubGlobal(
        'fetch',
        vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);

            if (url.includes('company-options')) {
                return json({
                    companies: [
                        { id: 1, legal_name: 'OPERADORA UNO' },
                        { id: 2, legal_name: 'OPERADORA DOS' },
                    ],
                });
            }

            if (url.includes('/api/reports/download')) {
                downloads.push(url);

                return new Response(new Blob(['xlsx']), {
                    status: 200,
                    headers: {
                        'Content-Type': 'application/octet-stream',
                        'Content-Disposition': 'attachment; filename="reporte.xlsx"',
                    },
                });
            }

            return json({ data: [], meta: {}, groups: [] });
        }),
    );

    const originalClick = HTMLAnchorElement.prototype.click;
    const originalCreate = URL.createObjectURL;
    const originalRevoke = URL.revokeObjectURL;

    HTMLAnchorElement.prototype.click = function click(this: HTMLAnchorElement) {
        // The blob URL is not the request URL; the fetch stub records the real
        // one, so the click only has to be harmless in jsdom.
        void this;
    };
    URL.createObjectURL = vi.fn(() => 'blob:reporte');
    URL.revokeObjectURL = vi.fn();

    const wrapper = mount(ReportesPage, { global: { stubs: { RouterLink: true } } });

    await flushPromises();

    return {
        wrapper,
        downloads,
        restore: () => {
            HTMLAnchorElement.prototype.click = originalClick;
            URL.createObjectURL = originalCreate;
            URL.revokeObjectURL = originalRevoke;
        },
    };
}

describe('ReportesPage — el libro operativo', () => {
    beforeEach(() => {
        resetCsrfState();
    });

    it('offers the workbook as its own control, not as a fourth format button', async () => {
        const { wrapper, restore } = await mounted();

        restore();

        const heading = wrapper.find('#libro-operativo-title');

        expect(heading.exists()).toBe(true);
        expect(heading.text()).toBe('Exportar libro operativo (formato original)');

        // Distinct from the flat reports: its own card with its own year and months.
        expect(wrapper.find('#libro-anio').exists()).toBe(true);
        expect(wrapper.find('#libro-desde').exists()).toBe(true);
        expect(wrapper.find('#libro-hasta').exists()).toBe(true);
        expect(wrapper.find('#libro-empresa').exists()).toBe(true);
    });

    it('asks for the type, format, year and month range the server expects', async () => {
        const { wrapper, downloads, restore } = await mounted();

        await wrapper.findAll('button').find((b) => b.text().trim() === 'Descargar XLSX')?.trigger('click');
        await flushPromises();
        restore();

        expect(downloads).toHaveLength(1);
        expect(downloads[0]).toContain('type=libro_operativo');
        expect(downloads[0]).toContain('format=xlsx');
        expect(downloads[0]).toContain('year=');
        expect(downloads[0]).toContain('months=1-12');
    });

    it('narrows to one month when the range is a single month', async () => {
        const { wrapper, downloads, restore } = await mounted();

        await wrapper.find('#libro-desde').setValue('3');
        await wrapper.find('#libro-hasta').setValue('3');
        await flushPromises();

        await wrapper.findAll('button').find((b) => b.text().trim() === 'Descargar XLSX')?.trigger('click');
        await flushPromises();
        restore();

        expect(downloads[0]).toContain('months=3');
    });

    it('says so when the range runs backwards, and does not offer the download', async () => {
        const { wrapper, downloads, restore } = await mounted();

        await wrapper.find('#libro-desde').setValue('10');
        await wrapper.find('#libro-hasta').setValue('3');
        await flushPromises();

        expect(wrapper.text()).toContain('El mes «hasta» es anterior al mes «desde»');
        // `attributes('disabled')` is `''` for a present boolean attribute, so the DOM property
        // is the honest assertion.
        const boton = wrapper
            .findAll('button')
            .find((b) => b.text().trim() === 'Descargar XLSX');

        expect((boton?.element as HTMLButtonElement).disabled).toBe(true);

        restore();
        expect(downloads).toHaveLength(0);
    });

    it('offers the years on both sides of today, so 2027 works', async () => {
        const { wrapper, restore } = await mounted();

        restore();

        const years = wrapper
            .findAll('#libro-anio option')
            .map((option) => option.text());

        const actual = new Date().getFullYear();

        expect(years).toContain(String(actual));
        expect(years).toContain(String(actual + 1));
    });

    it('shows the colour legend and says what each colour is computed from', async () => {
        const { wrapper, restore } = await mounted();

        restore();

        const leyenda = wrapper.find('.cdh-leyenda');

        expect(leyenda.exists()).toBe(true);

        const text = leyenda.text();

        expect(text).toContain('Verde — pagado');
        expect(text).toContain('Amarillo — pendiente');
        expect(text).toContain('Rojo — vencido');
        expect(text).toContain('RETIRADO');
        expect(text).toContain('Sin color');
        expect(text).toContain('Sin información de pago');

        // The sentence that stops somebody reading "no obligation" as "paid".
        expect(text).toContain('no significa que la persona pagó, ni que está en mora');
    });

    it('hides the whole card from a role that may look but not export', async () => {
        const { wrapper, restore } = await mounted(['reports.view']);

        restore();

        expect(wrapper.find('#libro-operativo-title').exists()).toBe(false);
    });
});