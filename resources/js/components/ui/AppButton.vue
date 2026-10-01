<script setup lang="ts">
import { RouterLink } from 'vue-router';

import type { RouteLocationRaw } from 'vue-router';

/**
 * Button with a consistent loading state.
 *
 * While busy the control is disabled and carries `aria-busy`, so neither a
 * keyboard user nor a screen reader can trigger the action twice.
 *
 * Given a `to`, it renders a link that looks like the button rather than a
 * button inside a link. A `<button>` nested in an `<a>` is interactive content
 * inside interactive content: browsers follow the link, but the two elements
 * disagree about focus and about what Enter does, and a screen reader announces
 * a control whose behaviour it cannot describe.
 */
const props = withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
        type?: 'button' | 'submit' | 'reset';
        busy?: boolean;
        block?: boolean;
        disabled?: boolean;
        /** Renders a router link instead of a button. */
        to?: RouteLocationRaw | null;
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
        to: null,
        icon: null,
        iconEnd: null,
    },
);

const emit = defineEmits<{ click: [MouseEvent] }>();

const classes = [
    'cdh-btn',
    `cdh-btn--${props.variant}`,
    { 'cdh-btn--block': props.block },
    'btn',
    props.variant === 'primary' && 'btn-sm',
    props.variant === 'secondary' && 'btn-sm',
    props.variant === 'danger' && 'btn-sm',
];
</script>

<template>
    <RouterLink v-if="to !== null" :to="to" :class="classes">
        <i v-if="icon" class="bi" :class="icon" aria-hidden="true" />

        <span><slot /></span>

        <i v-if="iconEnd" class="bi" :class="iconEnd" aria-hidden="true" />
    </RouterLink>

    <button
        v-else
        :class="classes"
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