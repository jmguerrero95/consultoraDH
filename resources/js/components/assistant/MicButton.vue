<script setup lang="ts">
import { computed } from 'vue';

import type { VoiceDictation } from '@/composables/useVoice';

/**
 * The microphone control in the composer.
 *
 * Shown whenever speech recognition exists. When it does not, the button is
 * replaced by nothing at all rather than by a disabled icon: a control that
 * cannot ever work is noise, and the person still has the keyboard's own
 * dictation key in the same field.
 */
const props = defineProps<{ dictation: VoiceDictation }>();

const emit = defineEmits<{
    /** The finalised transcript, ready to be appended to the draft. */
    send: [text: string];
}>();

const listening = computed(() => props.dictation.listening.value);
const supported = computed(() => props.dictation.supported.value);

/** What the person would read right now: final words plus anything pending. */
const live = computed(() =>
    [props.dictation.transcript.value, props.dictation.interim.value].filter(Boolean).join(' ').trim(),
);
</script>

<template>
    <div v-if="supported" class="cdh-mic">
        <button
            type="button"
            class="cdh-mic__button"
            :class="{ 'cdh-mic__button--live': listening }"
            :aria-pressed="listening"
            :aria-label="listening ? 'Detener el dictado' : 'Dictar con el micrófono'"
            :title="listening ? 'Detener el dictado' : 'Dictar con el micrófono'"
            data-test="mic-toggle"
            @click="listening ? dictation.stop() : dictation.start()"
        >
            <span v-if="listening" class="cdh-mic__pulse" aria-hidden="true" />
            <i class="bi" :class="listening ? 'bi-mic-fill' : 'bi-mic'" aria-hidden="true" />
        </button>

        <p v-if="dictation.error.value" class="cdh-mic__error" role="alert" data-test="mic-error">
            {{ dictation.error.value }}
        </p>

        <div v-else-if="live" class="cdh-mic__transcript" data-test="mic-transcript">
            <p class="cdh-mic__transcript-text">{{ live }}</p>

            <div class="cdh-mic__actions">
                <button
                    v-if="!listening"
                    type="button"
                    class="cdh-mic__action"
                    data-test="mic-accept"
                    @click="emit('send', live); dictation.reset()"
                >
                    Añadir al mensaje
                </button>

                <button
                    type="button"
                    class="cdh-mic__action cdh-mic__action--quiet"
                    data-test="mic-discard"
                    @click="dictation.reset()"
                >
                    Descartar
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.cdh-mic {
    display: flex;
    flex-direction: column;
    gap: var(--cdh-space-2, 0.5rem);
}

.cdh-mic__button {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    /* 44px is the smallest target that is reliably tappable with a thumb; a
       smaller icon makes the control unusable on a phone. */
    width: 44px;
    height: 44px;
    border: 1px solid var(--cdh-border, #d5d9e0);
    border-radius: 50%;
    background: var(--cdh-surface, #fff);
    color: var(--cdh-text, #1f2430);
    cursor: pointer;
}

.cdh-mic__button:focus-visible {
    outline: 2px solid var(--cdh-primary, #2563eb);
    outline-offset: 2px;
}

.cdh-mic__button--live {
    background: var(--cdh-danger, #b91c1c);
    border-color: var(--cdh-danger, #b91c1c);
    color: #fff;
}

.cdh-mic__pulse {
    position: absolute;
    inset: -4px;
    border-radius: 50%;
    border: 2px solid currentColor;
    opacity: 0.5;
    animation: cdh-mic-pulse 1.4s ease-out infinite;
}

@keyframes cdh-mic-pulse {
    from {
        transform: scale(0.9);
        opacity: 0.5;
    }

    to {
        transform: scale(1.25);
        opacity: 0;
    }
}

/* A moving element is decorative; honour the system preference rather than
   forcing an animation on someone who asked for none. */
@media (prefers-reduced-motion: reduce) {
    .cdh-mic__pulse {
        animation: none;
    }
}

.cdh-mic__error {
    margin: 0;
    font-size: 0.8125rem;
    color: var(--cdh-danger, #b91c1c);
}

.cdh-mic__transcript {
    border: 1px solid var(--cdh-border, #d5d9e0);
    border-radius: var(--cdh-radius-md, 0.375rem);
    padding: var(--cdh-space-2, 0.5rem);
    background: var(--cdh-surface-sunken, #f6f7f9);
}

.cdh-mic__transcript-text {
    margin: 0 0 0.5rem;
    font-size: 0.875rem;
    white-space: pre-wrap;
}

.cdh-mic__actions {
    display: flex;
    gap: 0.5rem;
}

.cdh-mic__action {
    border: 1px solid var(--cdh-border, #d5d9e0);
    border-radius: var(--cdh-radius-sm, 0.25rem);
    background: var(--cdh-surface, #fff);
    color: var(--cdh-primary, #2563eb);
    font-size: 0.8125rem;
    padding: 0.25rem 0.5rem;
    cursor: pointer;
}

.cdh-mic__action--quiet {
    color: var(--cdh-text-muted, #5b6472);
}
</style>