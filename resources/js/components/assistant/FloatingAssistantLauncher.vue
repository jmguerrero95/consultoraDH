<script setup lang="ts">
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue';

import { useAuthStore } from '@/stores/auth';

/**
 * The assistant launcher.
 *
 * ## Why the panel is a separate chunk (PERF-01 B01/B02)
 *
 * `FloatingAssistant.vue` contains the conversation, the attachment polling,
 * the proposal flow, the voice composer and all of its styles. It used to be
 * imported by the admin shell, so every authenticated page downloaded and
 * parsed it even though the panel stays closed in most sessions. This launcher
 * is what the shell mounts now; the panel is a dynamic import, fetched the
 * first time somebody opens it (or on load when it was left open).
 *
 * Behaviour is unchanged: visibility follows `ai.use` only (a failed status
 * call never removes the entry point), the panel is hidden rather than
 * destroyed after the first open, and the open/closed flag keeps the same
 * `localStorage` key.
 */

const auth = useAuthStore();

const canUse = computed(() => auth.can('ai.use'));

const STORAGE_OPEN = 'cdh.assistant.open';

const open = ref(false);
const loaded = ref(false);

const panel = defineAsyncComponent(() => import('./FloatingAssistant.vue'));

function storedOpen(): boolean {
    try {
        return window.localStorage.getItem(STORAGE_OPEN) === '1';
    } catch {
        return false;
    }
}

function persistOpen(value: boolean): void {
    try {
        window.localStorage.setItem(STORAGE_OPEN, value ? '1' : '0');
    } catch {
        // Storage can be unavailable (private mode, policy). The panel still
        // works for this session; it just will not reopen on the next load.
    }
}

function toggle(): void {
    loaded.value = true;
    open.value = !open.value;
    persistOpen(open.value);
}

function close(): void {
    open.value = false;
    persistOpen(false);
}

function openStored(): void {
    loaded.value = true;
    open.value = true;
}

onMounted(() => {
    if (canUse.value && storedOpen()) {
        openStored();
    }
});

// BUG-019. The permission list can arrive after mount while the session is
// restored; the launcher appears as soon as it does, without loading the panel.
watch(canUse, (value) => {
    if (value && storedOpen()) {
        openStored();
    }
});
</script>

<template>
    <div v-if="canUse" class="cdh-assistant">
        <!-- Shown to anyone who may use the assistant, whatever the status call
             answered: a status failure or a provider that is momentarily down
             must not silently remove the entry point. The real state is
             explained inside the panel. -->
        <button
            v-if="!open"
            type="button"
            class="cdh-assistant__fab"
            :aria-expanded="open"
            aria-controls="cdh-assistant-panel"
            aria-label="Abrir el asistente de IA"
            @click="toggle"
        >
            <i class="bi bi-robot" aria-hidden="true" />
        </button>

        <component :is="panel" v-if="loaded" v-show="open" :active="open" @close="close" />
    </div>
</template>

<style scoped>
.cdh-assistant {
    position: fixed;
    right: 1.25rem;
    bottom: 1.25rem;
    z-index: 1080;
}

.cdh-assistant__fab {
    width: 3.25rem;
    height: 3.25rem;
    border: 0;
    border-radius: 50%;
    background-color: var(--cdh-brand, #123a5e);
    color: #fff;
    font-size: 1.4rem;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
    cursor: pointer;
}

.cdh-assistant__fab:hover {
    filter: brightness(1.1);
}
</style>
