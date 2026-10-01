<script setup lang="ts">
import { useToastStore } from '@/stores/toast';

/**
 * Renders the toast queue.
 *
 * The container is a polite live region so a notification is announced without
 * stealing focus, and each toast can also be dismissed from the keyboard.
 */
const toasts = useToastStore();
</script>

<template>
    <div class="cdh-toasts" role="region" aria-label="Notificaciones">
        <div aria-live="polite" aria-atomic="false" class="d-contents">
            <div
                v-for="toast in toasts.toasts"
                :key="toast.id"
                class="cdh-toast"
                :class="`cdh-toast--${toast.variant}`"
            >
                <i
                    class="bi mt-1"
                    :class="{
                        'bi-check-circle': toast.variant === 'success',
                        'bi-exclamation-octagon': toast.variant === 'danger',
                        'bi-exclamation-triangle': toast.variant === 'warning',
                        'bi-info-circle': toast.variant === 'info',
                    }"
                    aria-hidden="true"
                />

                <div class="cdh-toast__body">
                    <p class="cdh-toast__title">{{ toast.title }}</p>
                    <p v-if="toast.message" class="cdh-toast__message">{{ toast.message }}</p>
                </div>

                <button
                    type="button"
                    class="cdh-toast__close"
                    :aria-label="`Descartar notificación: ${toast.title}`"
                    @click="toasts.dismiss(toast.id)"
                >
                    <i class="bi bi-x-lg" aria-hidden="true" />
                </button>
            </div>
        </div>
    </div>
</template>
