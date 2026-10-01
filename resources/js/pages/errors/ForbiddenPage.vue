<script setup lang="ts">

import AppButton from '@/components/ui/AppButton.vue';

/**
 * Access denied.
 *
 * Reached when the server answers 403. The interface never tries to work
 * around an authorisation decision, and the screen offers the routes the user
 * is actually allowed to reach.
 */
</script>

<template>
    <div class="cdh-error-page">
        <p class="cdh-error-page__code">403</p>

        <h1 class="cdh-error-page__title">Acceso denegado</h1>

        <p class="cdh-error-page__text">
            No tiene permisos para consultar esta sección. Si considera que se trata de un
            error, solicite a un administrador de la plataforma que revise sus roles.
        </p>

        <AppButton variant="primary" icon="bi-house-door" to="/">Ir al inicio</AppButton>
    </div>
</template>
