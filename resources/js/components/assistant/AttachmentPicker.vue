<script setup lang="ts">
/**
 * Attach a document to the conversation.
 *
 * ## Why a label around a hidden input rather than a styled div
 *
 * Because a `div` with a click handler is not reachable by keyboard and is not
 * announced as a control. A real `<input type="file">` inside a `<label>` gets
 * focus, announces its accepted formats, and opens the right picker on every
 * platform including mobile - which is also what the Playwright journey drives.
 *
 * ## Why the formats are declared in `accept`
 *
 * So the phone offers the right picker and the desktop filters the dialog. It
 * is a hint, not a control: the server validates the extension, the size and
 * the file signature independently, and says so.
 *
 * ## What this does not do
 *
 * It does not upload. It reports the chosen file upwards, and the page decides
 * what to do with it - so there is exactly one place that talks to the queue and
 * exactly one set of states for a task.
 */
import { ref } from 'vue';

// The template reads `busy` and `disabled` directly, so the declaration is
// not bound to a variable.
defineProps<{
    busy?: boolean;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    (e: 'choose', file: File): void;
}>();

/** The formats the ingestor accepts. Kept in step with the server's list. */
const ACCEPT = '.pdf,.docx,.xlsx,.csv,.txt,.md';

const chosen = ref<File | null>(null);

function onChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0] ?? null;

    if (file === null) {
        return;
    }

    chosen.value = file;
    emit('choose', file);

    // Cleared so choosing the same file twice in a row still fires `change`.
    // Without this the second selection is a no-op and the person is told
    // nothing happened.
    target.value = '';
}
</script>

<template>
    <div class="cdh-attach">
        <label
            class="cdh-attach__label"
            :class="{ 'cdh-attach__label--disabled': disabled || busy }"
            data-testid="attachment-label"
        >
            <span class="visually-hidden">Adjuntar un documento</span>

            <input
                type="file"
                class="cdh-attach__input"
                data-testid="attachment-input"
                :accept="ACCEPT"
                :disabled="disabled || busy"
                @change="onChange"
            />

            <span aria-hidden="true">📎</span>
        </label>

        <span v-if="chosen" class="cdh-attach__name" data-testid="attachment-name">{{ chosen.name }}</span>
    </div>
</template>

<style scoped>
.cdh-attach {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-width: 0;
}

.cdh-attach__label {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.5rem;
    height: 2.5rem;
    /* A real target on a phone: 40px is the smallest comfortable tap. */
    border: 1px solid #cbd5e1;
    border-radius: 0.5rem;
    cursor: pointer;
    background: #fff;
    flex: 0 0 auto;
}

.cdh-attach__label--disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.cdh-attach__input {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.cdh-attach__input:focus-visible + span {
    outline: 2px solid #4f46e5;
    outline-offset: 2px;
}

.cdh-attach__name {
    font-size: 0.75rem;
    color: #475569;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
}
</style>
