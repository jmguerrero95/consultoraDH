<script setup lang="ts">
/**
 * Inline status or error message.
 *
 * Used for form level feedback, where a full toast would be too detached from
 * the control it refers to. Errors are announced assertively, everything else
 * politely, so a screen reader reacts at the right moment.
 */
withDefaults(
    defineProps<{
        variant?: 'success' | 'danger' | 'warning' | 'info';
        title?: string | null;
    }>(),
    {
        variant: 'info',
        title: null,
    },
);

const icons: Record<string, string> = {
    success: 'bi-check-circle',
    danger: 'bi-exclamation-octagon',
    warning: 'bi-exclamation-triangle',
    info: 'bi-info-circle',
};
</script>

<template>
    <div
        class="cdh-alert"
        :class="`cdh-alert--${variant}`"
        :role="variant === 'danger' ? 'alert' : 'status'"
        :aria-live="variant === 'danger' ? 'assertive' : 'polite'"
    >
        <i class="bi" :class="icons[variant]" aria-hidden="true" />

        <div>
            <p v-if="title" class="cdh-alert__title">{{ title }}</p>
            <slot />
        </div>
    </div>
</template>
