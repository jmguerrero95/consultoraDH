<script setup lang="ts">
/**
 * Busy indicator for a whole region.
 *
 * `role="status"` plus `aria-live="polite"` means the wait is announced without
 * interrupting whatever the user is doing.
 */
withDefaults(defineProps<{ label?: string; large?: boolean }>(), {
    label: 'Cargando…',
    large: false,
});
</script>

<template>
    <div class="cdh-loading" role="status" aria-live="polite">
        <span
            class="cdh-spinner"
            :class="{ 'cdh-spinner--lg': large }"
            aria-hidden="true"
        />
        <span class="cdh-loading__text">{{ label }}</span>
    </div>
</template>
