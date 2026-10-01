<script setup lang="ts">
/**
 * Consultora DH wordmark.
 *
 * A text and CSS identity that can be refined later without touching any
 * screen: swapping this component for a graphical logo is a one file change.
 */
withDefaults(
    defineProps<{
        /** Renders the light variant, for use on the dark panels. */
        light?: boolean;
        /** Renders the shorter, sidebar-sized variant. */
        compact?: boolean;
        /** Hides the descriptor line when space is tight. */
        hideDescriptor?: boolean;
    }>(),
    {
        light: false,
        compact: false,
        hideDescriptor: false,
    },
);
</script>

<template>
    <span
        class="cdh-wordmark"
        :class="{ 'cdh-wordmark--light': light, 'cdh-wordmark--sm': compact }"
    >
        <span class="cdh-wordmark__mark" aria-hidden="true">CD</span>

        <span class="cdh-wordmark__text">
            <span class="cdh-wordmark__name">Consultora&nbsp;DH</span>
            <span v-if="!hideDescriptor" class="cdh-wordmark__descriptor">
                Gestión administrativa
            </span>
        </span>
    </span>
</template>
