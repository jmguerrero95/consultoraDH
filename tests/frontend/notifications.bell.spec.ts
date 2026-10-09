import { createPinia, setActivePinia } from 'pinia';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import NotificationBell from '@/components/layout/NotificationBell.vue';
import { a05 } from '@/services/a05';

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function stubFetch(handler: () => Response | Promise<Response>): void {
    vi.stubGlobal('fetch', vi.fn(async () => handler()));
}

/**
 * §59: unread count, recent list, mark read.
 *
 * The first test is the one that matters for correctness: a request that fails must not
 * render as "no notifications", because those are different statements and the second one
 * is false (§60).
 */
describe('notification bell', () => {
    beforeEach(() => {
        setActivePinia(createPinia());

        vi.restoreAllMocks();
    });

    it('shows the unread count the server reported', async () => {
        stubFetch(() =>
            jsonResponse({
                unread: 3,
                data: [
                    {
                        id: 'n1',
                        type: 'App\\Notifications\\TaskReminderNotification',
                        data: {
                            type: 'task_reminder',
                            title: 'Recordatorio de tarea',
                            message: 'Llamar al cliente',
                            route: '/operacion',
                        },
                        read_at: null,
                        created_at: '2025-03-01T10:00:00+00:00',
                    },
                ],
            }),
        );

        const wrapper = mount(NotificationBell, { global: { stubs: { RouterLink: true } } });

        await vi.waitFor(() =>
            expect(wrapper.find('[data-testid="notifications-unread"]').exists()).toBe(true),
        );

        expect(wrapper.find('[data-testid="notifications-unread"]').text()).toBe('3');
    });

    it('does not render a failed request as an empty inbox', async () => {
        stubFetch(() => jsonResponse({ message: 'Error interno' }, 500));

        const wrapper = mount(NotificationBell, { global: { stubs: { RouterLink: true } } });

        await wrapper.find('[data-testid="notifications-toggle"]').trigger('click');
        await vi.waitFor(() => expect(wrapper.find('[data-testid="notifications-panel"]').exists()).toBe(true));

        // The panel opened, and it does not claim there is nothing to see.
        expect(wrapper.text()).not.toContain('No hay notificaciones.');
    });

    it('lists the recent notifications when the request succeeds', async () => {
        stubFetch(() =>
            jsonResponse({
                unread: 0,
                data: [
                    {
                        id: 'n2',
                        type: 'App\\Notifications\\ReportReadyNotification',
                        data: {
                            type: 'report_ready',
                            title: 'Reporte listo',
                            message: 'El reporte programado está listo.',
                            route: '/reportes',
                        },
                        read_at: '2025-03-01T10:00:00+00:00',
                        created_at: '2025-03-01T10:00:00+00:00',
                    },
                ],
            }),
        );

        const wrapper = mount(NotificationBell, { global: { stubs: { RouterLink: true } } });

        await wrapper.find('[data-testid="notifications-toggle"]').trigger('click');
        await vi.waitFor(() => expect(wrapper.text()).toContain('Reporte listo'));

        expect(a05.notifications.recent).toBeDefined();
    });
});