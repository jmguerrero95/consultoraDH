<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import AppWordmark from '@/components/brand/AppWordmark.vue';
import { useAuthStore } from '@/stores/auth';

import type { RouteRecordNormalized } from 'vue-router';

/**
 * Primary navigation.
 *
 * Entries are derived from the router's own metadata, so a new screen appears
 * in the sidebar by declaring `meta.nav` and nothing else. Entries the current
 * user may not open are filtered out; the server checks them again regardless.
 */
const auth = useAuthStore();
const route = useRoute();
// The injected router, not a module singleton, so the component is testable and
// has no hidden dependency on how the application was bootstrapped.
const router = useRouter();

const appVersion = computed(
    () => document.querySelector<HTMLMetaElement>('meta[name="app-version"]')?.content ?? '0.0.0',
);

const entries = computed(() => {
    // The shell is the route record that owns the authenticated screens: it is
    // the one that both requires a session and has children. Looking a child up
    // by name instead would silently return a leaf, and the menu would be empty.
    const layout = router
        .getRoutes()
        .find((record) => record.children.length > 0 && record.meta.requiresAuth === true);

    const children = (layout?.children ?? []) as RouteRecordNormalized[];

    // The shell is only reachable behind the navigation guard, but rendering
    // nothing for an unauthenticated store means a routing mistake cannot
    // expose the menu.
    if (auth.user === null) {
        return [];
    }

    return children
        .filter((record) => record.meta.nav !== undefined)
        // Every entry declares the permission it needs, so a new screen appears
        // in the menu by adding its route record and nothing else.
        .filter((record) =>
            record.meta.permission === undefined ? true : auth.can(record.meta.permission),
        )
        .sort((a, b) => (a.meta.nav?.order ?? 0) - (b.meta.nav?.order ?? 0));
});

/** Highlight the section the user is currently in. */
function isActive(name: RouteRecordNormalized['name']): boolean {
    return route.name === name;
}
</script>

<template>
    <div class="cdh-sidebar__header">
        <RouterLink to="/" class="cdh-wordmark cdh-wordmark--light cdh-wordmark--sm">
            <AppWordmark light compact hide-descriptor />
        </RouterLink>
    </div>

    <nav class="cdh-sidebar__nav" aria-label="Navegación principal">
        <p class="cdh-sidebar__section-label">Plataforma</p>

        <RouterLink
            v-for="entry in entries"
            :key="String(entry.name)"
            :to="{ name: entry.name }"
            class="cdh-nav-link"
            :class="{ 'cdh-nav-link--active': isActive(entry.name) }"
            :aria-current="isActive(entry.name) ? 'page' : undefined"
        >
            <i class="bi" :class="entry.meta.nav?.icon" aria-hidden="true" />
            <span>{{ entry.meta.nav?.label }}</span>
        </RouterLink>
    </nav>

    <div class="cdh-sidebar__footer">
        <p class="mb-0">Consultora DH · v{{ appVersion }}</p>
    </div>
</template>
