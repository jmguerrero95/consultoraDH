<script setup lang="ts">
import { useRoute } from 'vue-router';

import AppWordmark from '@/components/brand/AppWordmark.vue';
import NotificationBell from '@/components/layout/NotificationBell.vue';
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
            A05 made this real. The unread count and the recent list exist because a task
            reminder and a finished scheduled report have to reach the person they concern
            without anybody watching a scheduler log.
        -->
        <NotificationBell />

        <UserMenu v-if="auth.user" />
    </header>
</template>
