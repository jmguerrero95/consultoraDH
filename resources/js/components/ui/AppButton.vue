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
        /**
         * `sm` is for actions inside a table row, where a full size button makes the
         * row taller than the data it describes.
         */
        size?: 'sm' | 'md';
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
        size: 'md',
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
    { 'cdh-btn--sm': props.size === 'sm' },
    // Bootstrap's small sizing, which the design tokens already line up with.
    'btn',
    props.size === 'sm' && 'btn-sm',
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