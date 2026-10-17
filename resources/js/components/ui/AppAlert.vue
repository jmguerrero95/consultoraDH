<script setup lang="ts">
import { computed, useSlots } from 'vue';

/**
 * Inline status or error message.
 *
 * Used for form level feedback, where a full toast would be too detached from
 * the control it refers to. Errors are announced assertively, everything else
 * politely, so a screen reader reacts at the right moment.
 *
 * ## Why `message` and `description` exist
 *
 * Callers were passing `:message` and `:description` while the component only
 * declared `title`, so Vue left them on the root element as stray attributes
 * and the alert rendered as an empty coloured box. The props are declared here
 * so every one of those call sites now shows its text again, and the component
 * refuses to render at all when it has nothing meaningful to say — an empty
 * alert is indistinguishable from a broken one.
 */
const props = withDefaults(
    defineProps<{
        variant?: 'success' | 'danger' | 'warning' | 'info';
        title?: string | null;
        /** The main body text. */
        message?: string | null;
        /** Secondary line, shown under `message`; used alone when there is none. */
        description?: string | null;
    }>(),
    {
        variant: 'info',
        title: null,
        message: null,
        description: null,
    },
);

const slots = useSlots();

const hasTitle = computed(() => Boolean(props.title?.trim()));
const message = computed(() => props.message?.trim() ?? '');
const description = computed(() => props.description?.trim() ?? '');

/** The first paragraph: `message`, or the description when there is no message. */
const body = computed(() => message.value || description.value);
/** The description only gets its own paragraph when the message already took the first one. */
const secondary = computed(() => (message.value && description.value ? description.value : ''));

/**
 * A caller that passes only a slot is still meaningful; one that passes an
 * empty interpolation (`{{ aviso }}` with `aviso === ''`) is not. Text vnodes
 * are therefore inspected instead of trusting the slot's mere existence.
 */
const hasSlotContent = computed(() => {
    const nodes = slots.default?.();

    if (!nodes || nodes.length === 0) {
        return false;
    }

    return nodes.some((node) => {
        const children = (node as { children?: unknown }).children;

        if (typeof children === 'string') {
            return children.trim() !== '';
        }

        if (Array.isArray(children)) {
            return children.some((child) => typeof child !== 'string' || child.trim() !== '');
        }

        return true;
    });
});

const hasContent = computed(() => hasTitle.value || Boolean(body.value) || hasSlotContent.value);

const icons: Record<string, string> = {
    success: 'bi-check-circle',
    danger: 'bi-exclamation-octagon',
    warning: 'bi-exclamation-triangle',
    info: 'bi-info-circle',
};
</script>

<template>
    <div
        v-if="hasContent"
        class="cdh-alert"
        :class="`cdh-alert--${variant}`"
        :role="variant === 'danger' ? 'alert' : 'status'"
        :aria-live="variant === 'danger' ? 'assertive' : 'polite'"
    >
        <i class="bi" :class="icons[variant]" aria-hidden="true" />

        <div>
            <p v-if="hasTitle" class="cdh-alert__title">{{ title }}</p>
            <p v-if="body" class="cdh-alert__body">{{ body }}</p>
            <p v-if="secondary" class="cdh-alert__description">{{ secondary }}</p>
            <slot />
        </div>
    </div>
</template>
