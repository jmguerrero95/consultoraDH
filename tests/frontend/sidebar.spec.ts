import { createPinia, setActivePinia } from 'pinia';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

import { createMemoryHistory } from 'vue-router';

import AdminSidebar from '@/components/layout/AdminSidebar.vue';
import { createAppRouter } from '@/router';
import { useAuthStore } from '@/stores/auth';

import type { AuthUser } from '@/types/api';

function user(permissions: string[] = ['settings.view']): AuthUser {
    return {
        id: 1,
        name: 'Ana Restrepo',
        email: 'ana@consultora-dh.test',
        status: 'active',
        status_label: 'Activo',
        initials: 'AR',
        roles: ['Super Admin'],
        primary_role: 'Super Admin',
        permissions,
        last_login_at: null,
        email_verified_at: null,
    };
}

function signIn(permissions: string[] = ['settings.view']): void {
    const auth = useAuthStore();

    auth.setUser(user(permissions));
    auth.initialised = true;
}

/** Mount the sidebar against a real router built with a memory history. */
function mountSidebar() {
    const router = createAppRouter(createMemoryHistory());

    return mount(AdminSidebar, { global: { plugins: [router] } });
}

describe('AdminSidebar', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    it('lists every entry declared with router metadata', () => {
        signIn();

        const labels = mountSidebar()
            .findAll('.cdh-nav-link')
            .map((link) => link.text());

        expect(labels).toEqual(['Inicio', 'Mi perfil', 'Configuración']);
    });

    it('does not list a route twice', () => {
        signIn();

        // "Inicio" is reachable at both `/` and `/dashboard`; the navigation
        // must show one entry, not two identical links.
        const homeLinks = mountSidebar().findAll('.cdh-nav-link').filter((link) =>
            link.text().includes('Inicio'),
        );

        expect(homeLinks).toHaveLength(1);
    });

    it('omits the entries the user is not allowed to open', () => {
        // Read Only has no `settings.view`.
        signIn([]);

        const labels = mountSidebar()
            .findAll('.cdh-nav-link')
            .map((link) => link.text());

        expect(labels).toEqual(['Inicio', 'Mi perfil']);
    });

    it('shows no entries at all to a guest', () => {
        expect(mountSidebar().findAll('.cdh-nav-link')).toHaveLength(0);
    });

    it('marks the entry of the current screen', async () => {
        signIn();

        const router = createAppRouter(createMemoryHistory());

        await router.push('/profile');

        const active = mount(AdminSidebar, { global: { plugins: [router] } })
            .findAll('.cdh-nav-link')
            .filter((link) => link.classes().includes('cdh-nav-link--active'))
            .map((link) => link.text());

        expect(active).toEqual(['Mi perfil']);
    });

    it('brands itself as Consultora DH', () => {
        signIn();

        expect(mountSidebar().text()).toContain('Consultora DH');
    });
});
