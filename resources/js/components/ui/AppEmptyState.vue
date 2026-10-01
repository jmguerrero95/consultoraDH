<script setup lang="ts">
/**
 * Placeholder shown where a module has no data yet.
 *
 * A01 uses it to explain that the operational modules are planned, instead of
 * leaving an unexplained blank area.
 */
withDefaults(
    defineProps<{
        title: string;
        icon?: string;
        /** Optional call to action. */
        actionLabel?: string | null;
    }>(),
    {
        icon: 'bi-inbox',
        actionLabel: null,
    },
);

const emit = defineEmits<{ action: [] }>();
</script>

<template>
    <div class="cdh-empty">
        <span class="cdh-empty__icon">
            <i class="bi" :class="icon" aria-hidden="true" />
        </span>

        <p class="cdh-empty__title">{{ title }}</p>

        <p class="cdh-empty__text">
            <slot />
        </p>

        <button
            v-if="actionLabel"
            type="button"
            class="cdh-btn cdh-btn--secondary btn btn-sm mt-3"
            @click="emit('action')"
        >
            {{ actionLabel }}
        </button>
    </div>
</template>
