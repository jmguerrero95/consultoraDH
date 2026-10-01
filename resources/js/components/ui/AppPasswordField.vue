<script setup lang="ts">
import { computed, useId } from 'vue';

/**
 * Password control with a show/hide toggle.
 *
 * The toggle is a real button whose accessible name reflects the current state,
 * so a screen reader user knows whether the field is masked, and a keyboard user
 * can reach it. The label, hint and error are wired exactly as in
 * AppFormField, which this control mirrors rather than wraps: a password input
 * needs an extra control inside the field, so the markup differs.
 */
const props = withDefaults(
    defineProps<{
        label: string;
        modelValue: string;
        name: string;
        placeholder?: string;
        required?: boolean;
        disabled?: boolean;
        autocomplete?: string;
        hint?: string | null;
        error?: string | null;
    }>(),
    {
        placeholder: undefined,
        required: false,
        disabled: false,
        autocomplete: 'current-password',
        hint: null,
        error: null,
    },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const uid = useId();
const controlId = computed(() => `password-${props.name}-${uid}`);
const hintId = computed(() => `${controlId.value}-hint`);
const errorId = computed(() => `${controlId.value}-error`);

const visible = defineModel<boolean>('visible', { default: false });

const inputType = computed(() => (visible.value ? 'text' : 'password'));
const toggleLabel = computed(() => (visible.value ? 'Ocultar contraseña' : 'Mostrar contraseña'));

const describedBy = computed(() => {
    const ids = [props.hint ? hintId.value : null, props.error ? errorId.value : null];

    return ids.filter(Boolean).join(' ') || undefined;
});

function toggle(): void {
    visible.value = !visible.value;
}
</script>

<template>
    <div>
        <label class="cdh-form-label" :for="controlId">
            {{ label }}
            <span v-if="required" class="cdh-form-label__required" aria-hidden="true">*</span>
            <span v-if="required" class="cdh-visually-hidden">(obligatorio)</span>
        </label>

        <div class="cdh-input-group">
            <input
                :id="controlId"
                class="form-control form-control-sm"
                :class="{ 'is-invalid': Boolean(error) }"
                :name="name"
                :type="inputType"
                :value="modelValue"
                :placeholder="placeholder"
                :required="required"
                :disabled="disabled"
                :autocomplete="autocomplete"
                :aria-describedby="describedBy"
                :aria-invalid="error ? 'true' : undefined"
                :aria-required="required ? 'true' : undefined"
                @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
            />

            <button
                type="button"
                class="cdh-input-group__action"
                :aria-label="toggleLabel"
                :aria-pressed="visible"
                :title="toggleLabel"
                :disabled="disabled"
                @click="toggle"
            >
                <i class="bi" :class="visible ? 'bi-eye-slash' : 'bi-eye'" aria-hidden="true" />
            </button>
        </div>

        <p v-if="hint" :id="hintId" class="cdh-form-hint">{{ hint }}</p>

        <p v-if="error" :id="errorId" class="cdh-form-error" role="alert">
            <i class="bi bi-exclamation-circle" aria-hidden="true" />
            <span>{{ error }}</span>
        </p>
    </div>
</template>
