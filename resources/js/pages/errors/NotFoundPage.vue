<script setup lang="ts">
import { RouterLink } from 'vue-router';

import AppButton from '@/components/ui/AppButton.vue';

/**
 * Not found.
 *
 * Served by the single page application for any unknown path, so a mistyped or
 * outdated link produces a real screen rather than a blank document.
 */
</script>

<template>
    <div class="cdh-error-page">
        <p class="cdh-error-page__code">404</p>

        <h1 class="cdh-error-page__title">Página no encontrada</h1>

        <p class="cdh-error-page__text">
            La dirección solicitada no existe o fue movida. Verifique el enlace e intente
            nuevamente.
        </p>

        <RouterLink to="/">
            <AppButton variant="primary" icon="bi-house-door">Ir al inicio</AppButton>
        </RouterLink>
    </div>
</template>
