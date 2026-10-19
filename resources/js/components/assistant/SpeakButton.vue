<script setup lang="ts">
import { computed } from 'vue';

import type { VoicePlayback } from '@/composables/useVoice';

/**
 * Read-aloud controls for one assistant reply.
 *
 * Rendered only where `speechSynthesis` exists. A reply with no reading control
 * is not a defect: the text is on screen and selectable either way.
 */
const props = defineProps<{ playback: VoicePlayback }>();

const emit = defineEmits<{
    /** Read the reply this control sits next to. */
    speak: [];
}>();

const supported = computed(() => props.playback.supported.value);
const speaking = computed(() => props.playback.speaking.value);
const paused = computed(() => props.playback.paused.value);

// A browser without a working `pause()` shows only play/stop, rather than a
// pause control that would silently do nothing.
const pausable = computed(() => props.playback.pausable.value);
</script>

<template>
    <div v-if="supported" class="cdh-speak" data-test="speak-controls">
        <button
            v-if="!speaking"
            type="button"
            class="cdh-speak__button"
            aria-label="Leer la respuesta en voz alta"
            title="Leer la respuesta en voz alta"
            data-test="speak-start"
            @click="emit('speak')"
        >
            <i class="bi bi-volume-up" aria-hidden="true" />
        </button>

        <template v-else>
            <button
                v-if="pausable && !paused"
                type="button"
                class="cdh-speak__button"
                aria-label="Pausar la lectura"
                title="Pausar la lectura"
                data-test="speak-pause"
                @click="playback.pause()"
            >
                <i class="bi bi-pause-fill" aria-hidden="true" />
            </button>

            <button
                v-else-if="pausable"
                type="button"
                class="cdh-speak__button"
                aria-label="Continuar la lectura"
                title="Continuar la lectura"
                data-test="speak-resume"
                @click="playback.resume()"
            >
                <i class="bi bi-play-fill" aria-hidden="true" />
            </button>

            <button
                type="button"
                class="cdh-speak__button"
                aria-label="Detener la lectura"
                title="Detener la lectura"
                data-test="speak-stop"
                @click="playback.stop()"
            >
                <i class="bi bi-stop-fill" aria-hidden="true" />
            </button>
        </template>

        <p v-if="playback.error.value" class="cdh-speak__error" role="alert">
            {{ playback.error.value }}
        </p>
    </div>
</template>

<style scoped>
.cdh-speak {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.cdh-speak__button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    /* Same 44px target as the microphone, for the same reason. */
    width: 44px;
    height: 44px;
    border: 1px solid var(--cdh-border, #d5d9e0);
    border-radius: 50%;
    background: var(--cdh-surface, #fff);
    color: var(--cdh-text-muted, #5b6472);
    cursor: pointer;
}

.cdh-speak__button:focus-visible {
    outline: 2px solid var(--cdh-primary, #2563eb);
    outline-offset: 2px;
}

.cdh-speak__button:hover {
    color: var(--cdh-primary, #2563eb);
}

.cdh-speak__error {
    margin: 0;
    font-size: 0.8125rem;
    color: var(--cdh-danger, #b91c1c);
}
</style>