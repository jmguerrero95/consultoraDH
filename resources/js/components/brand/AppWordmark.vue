<script setup lang="ts">
import { computed } from 'vue';

/**
 * Consultora DH wordmark.
 *
 * The official artwork is used as delivered: the symbol, the name and the
 * slogan are never redrawn or replaced with generic initials. The component
 * chooses one of the two shipped lockups:
 *
 *  - `compact` shows the symbol plus the name, for the sidebar header and the
 *    mobile top bar, where the full lockup would be illegible.
 *  - otherwise the full lockup is shown on its own. The image already contains
 *    the name and the slogan, so no text is repeated next to it.
 *
 * `light` selects the transparent white artwork for the blue panels; the
 * default selects the blue lockup for light surfaces.
 */
const props = withDefaults(
    defineProps<{
        /** Renders the light variant, for use on the blue panels. */
        light?: boolean;
        /** Renders the symbol plus name, for tight headers. */
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

/** Full lockup: already carries the name and the slogan. */
const fullLogo = computed(() =>
    props.light
        ? {
              src: '/branding/logo-completo-blanco-transparente.png',
              width: 872,
              height: 786,
          }
        : {
              src: '/branding/logo-completo-azul-1024.png',
              width: 1024,
              height: 1024,
          },
);

/**
 * Compact mark: the white symbol on the blue sidebar, or the official app icon
 * on light surfaces. Neither contains the name, so the text below completes it.
 */
const compactMark = computed(
    () =>
        props.light
            ? { src: '/branding/simbolo-blanco-transparente.png' }
            : { src: '/icons/icon-192x192.png' },
);
</script>

<template>
    <span
        class="cdh-wordmark"
        :class="{ 'cdh-wordmark--light': light, 'cdh-wordmark--sm': compact }"
    >
        <template v-if="compact">
            <img
                class="cdh-wordmark__mark"
                :src="compactMark.src"
                alt=""
                aria-hidden="true"
                width="32"
                height="32"
                decoding="async"
            />

            <span class="cdh-wordmark__text">
                <span class="cdh-wordmark__name">Consultora&nbsp;DH</span>
                <span v-if="!hideDescriptor" class="cdh-wordmark__descriptor">
                    Profesionales en seguros
                </span>
            </span>
        </template>

        <img
            v-else
            class="cdh-wordmark__full"
            :src="fullLogo.src"
            :width="fullLogo.width"
            :height="fullLogo.height"
            alt="Consultora DH — Profesionales en seguros"
            decoding="async"
        />
    </span>
</template>
