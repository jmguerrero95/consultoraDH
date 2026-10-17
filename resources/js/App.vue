<script setup lang="ts">
import { RouterView } from 'vue-router';

import { useNavigationProgress } from '@/composables/useNavigationProgress';

/**
 * Root component. Authentication screens render without a shell, so the
 * top level only has to choose between the routed screen and nothing else.
 *
 * The progress bar belongs here rather than in a layout: a route change happens
 * above both shells, and the login redirect is a navigation too. It is the one
 * piece of feedback a navigation can give once the button that started it has
 * been replaced by the incoming screen.
 */
const { navigating } = useNavigationProgress();
</script>

<template>
    <div
        v-if="navigating"
        class="cdh-nav-progress"
        role="progressbar"
        aria-label="Cargando la página"
    />

    <RouterView />
</template>
