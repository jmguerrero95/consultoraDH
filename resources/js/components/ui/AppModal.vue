<script setup lang="ts">
import { onBeforeUnmount, useId, useTemplateRef, watch } from 'vue';

import AppButton from '@/components/ui/AppButton.vue';

/**
 * Confirmation dialog.
 *
 * Built on the native <dialog> element so the browser provides the focus trap,
 * the inert backdrop and the Escape key. A01 uses it to confirm signing out.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        confirmLabel?: string;
        cancelLabel?: string;
        busy?: boolean;
        destructive?: boolean;
        /**
         * Blocks the confirm button without hiding it.
         *
         * For the case where the dialog is asking to confirm something the server
         * has already said is impossible: hiding the button would leave the reader
         * wondering what they are supposed to do, and disabling it explains.
         */
        disabled?: boolean;
        /** A wider dialog, for a dialog whose content is a table. */
        wide?: boolean;
        /**
         * Hides the confirm button.
         *
         * For a dialog that only shows something — a payment's history, a list of
         * pending periods — a "Confirmar" button does nothing when pressed. It is
         * replaced by the single dismiss button, so the dialog has no dead control.
         */
        hideConfirm?: boolean;
    }>(),
    {
        confirmLabel: 'Confirmar',
        cancelLabel: 'Cancelar',
        busy: false,
        destructive: false,
        disabled: false,
        wide: false,
        hideConfirm: false,
    },
);

const emit = defineEmits<{
    confirm: [];
    cancel: [];
    /**
     * Alias of `cancel`.
     *
     * Two call sites listened for `@close`, an event this component never emitted,
     * so their "Cancelar" button was inert. Emitting both keeps those callers — and
     * any future one that guesses `close` — working instead of silently dead.
     */
    close: [];
}>();

function requestCancel(): void {
    emit('cancel');
    emit('close');
}

const titleId = useId();
const bodyId = `${titleId}-body`;

const dialog = useTemplateRef<HTMLDialogElement>('dialog');

watch(
    () => props.open,
    (open) => {
        const element = dialog.value;

        if (element === null) {
            return;
        }

        if (open && !element.open) {
            element.showModal();
        } else if (!open && element.open) {
            element.close();
        }
    },
);

// If the element only exists after the first render, honour the initial state.
watch(dialog, (element) => {
    if (element !== null && props.open && !element.open) {
        element.showModal();
    }
});

function onNativeCancel(event: Event): void {
    // Escape pressed. Keep the native dismissal, then report it.
    event.preventDefault();
    requestCancel();
}

onBeforeUnmount(() => {
    const element = dialog.value;

    if (element?.open) {
        element.close();
    }
});
</script>

<template>
    <dialog
        ref="dialog"
        class="cdh-modal"
        :class="{ 'cdh-modal--wide': wide }"
        :aria-labelledby="titleId"
        :aria-describedby="bodyId"
        @cancel="onNativeCancel"
    >
        <div class="cdh-modal__header">
            <h2 :id="titleId" class="cdh-modal__title">{{ title }}</h2>
        </div>

        <div :id="bodyId" class="cdh-modal__body">
            <slot />
        </div>

        <div class="cdh-modal__footer">
            <AppButton variant="secondary" :disabled="busy" @click="requestCancel">
                {{ cancelLabel }}
            </AppButton>

            <AppButton
                v-if="!hideConfirm"
                :variant="destructive ? 'danger' : 'primary'"
                :busy="busy"
                :disabled="disabled"
                @click="emit('confirm')"
            >
                {{ confirmLabel }}
            </AppButton>
        </div>
    </dialog>
</template>
