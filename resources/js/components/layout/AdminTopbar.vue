<script setup lang="ts">
import { useRoute } from 'vue-router';

import AppWordmark from '@/components/brand/AppWordmark.vue';
import UserMenu from '@/components/layout/UserMenu.vue';
import { useAuthStore } from '@/stores/auth';

/**
 * Top navigation bar: mobile menu trigger, page context, notifications
 * placeholder and the account menu.
 */
const emit = defineEmits<{ 'toggle-sidebar': [] }>();

const auth = useAuthStore();
const route = useRoute();
</script>

<template>
    <header class="cdh-topbar">
        <button
            type="button"
            class="cdh-icon-btn cdh-topbar__toggle"
            aria-label="Abrir menú de navegación"
            @click="emit('toggle-sidebar')"
        >
            <i class="bi bi-list fs-5" aria-hidden="true" />
        </button>

        <span class="cdh-wordmark d-md-none">
            <AppWordmark compact hide-descriptor />
        </span>

        <!--
            Context only. The page heading itself is the h1 rendered by the
            routed screen, so this must not introduce a second one.
        -->
        <span class="cdh-topbar__title">{{ route.meta.title }}</span>

        <span class="cdh-topbar__spacer" />

        <!--
            Placeholder. Notifications arrive with the operational modules
            (A06, A10); the control is present so the layout is final.
        -->
        <button
            type="button"
            class="cdh-icon-btn"
            aria-label="Notificaciones (próximamente)"
            title="Notificaciones (próximamente)"
            disabled
        >
            <i class="bi bi-bell" aria-hidden="true" />
            <span class="cdh-icon-btn__dot" aria-hidden="true" />
        </button>

        <UserMenu v-if="auth.user" />
    </header>
</template>
