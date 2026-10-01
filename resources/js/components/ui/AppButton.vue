<script setup lang="ts">
/**
 * Button with a consistent loading state.
 *
 * While busy the control is disabled and carries `aria-busy`, so neither a
 * keyboard user nor a screen reader can trigger the action twice.
 */
withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
        type?: 'button' | 'submit' | 'reset';
        busy?: boolean;
        block?: boolean;
        disabled?: boolean;
        /** Icon class rendered before the label. */
        icon?: string | null;
        /** Icon class rendered after the label. */
        iconEnd?: string | null;
    }>(),
    {
        variant: 'primary',
        type: 'button',
        busy: false,
        block: false,
        disabled: false,
        icon: null,
        iconEnd: null,
    },
);

const emit = defineEmits<{ click: [MouseEvent] }>();
</script>

<template>
    <button
        class="cdh-btn"
        :class="[
            `cdh-btn--${variant}`,
            { 'cdh-btn--block': block },
            'btn',
            variant === 'primary' && 'btn-sm',
            variant === 'secondary' && 'btn-sm',
            variant === 'danger' && 'btn-sm',
        ]"
        :type="type"
        :disabled="disabled || busy"
        :aria-busy="busy ? 'true' : undefined"
        @click="emit('click', $event)"
    >
        <span v-if="busy" class="cdh-spinner" aria-hidden="true" />
        <i v-else-if="icon" class="bi" :class="icon" aria-hidden="true" />

        <span><slot /></span>

        <i v-if="iconEnd && !busy" class="bi" :class="iconEnd" aria-hidden="true" />
    </button>
</template>
