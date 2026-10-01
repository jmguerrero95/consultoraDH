<script setup lang="ts">
import { computed, useId } from 'vue';

/**
 * Labelled form control with hint and validation message.
 *
 * Owns the accessibility wiring that is easy to get wrong when it is repeated
 * in every page: a real <label> bound to the control, `aria-describedby` for
 * the hint and the error, and `aria-invalid` when validation failed.
 */
const props = withDefaults(
    defineProps<{
        label: string;
        modelValue: string;
        type?: string;
        name: string;
        placeholder?: string;
        required?: boolean;
        disabled?: boolean;
        autocomplete?: string;
        hint?: string | null;
        error?: string | null;
        inputmode?: 'text' | 'email' | 'tel' | 'url' | 'numeric' | 'search' | 'decimal' | null;
        /**
         * Choices, which turn the control into a select.
         *
         * The label, hint, error and aria wiring stay identical either way, which
         * is the reason for keeping one component: a page that assembled its own
         * select would have to remember all of it.
         */
        options?: { value: string; label: string }[];
        /** Value used when nothing should be shown as a real choice. */
        emptyLabel?: string | null;
    }>(),
    {
        type: 'text',
        placeholder: undefined,
        required: false,
        disabled: false,
        autocomplete: undefined,
        hint: null,
        error: null,
        inputmode: null,
        options: undefined,
        emptyLabel: null,
    },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const uid = useId();
const controlId = computed(() => `field-${props.name}-${uid}`);
const hintId = computed(() => `${controlId.value}-hint`);
const errorId = computed(() => `${controlId.value}-error`);

const describedBy = computed(() => {
    const ids = [props.hint ? hintId.value : null, props.error ? errorId.value : null];

    return ids.filter(Boolean).join(' ') || undefined;
});
</script>

<template>
    <div>
        <label class="cdh-form-label" :for="controlId">
            {{ label }}
            <span v-if="required" class="cdh-form-label__required" aria-hidden="true">*</span>
            <span v-if="required" class="cdh-visually-hidden">(obligatorio)</span>
        </label>

        <select
            v-if="options !== undefined"
            :id="controlId"
            class="form-control form-control-sm"
            :class="{ 'is-invalid': Boolean(error) }"
            :name="name"
            :required="required"
            :disabled="disabled"
            :value="modelValue"
            :aria-describedby="describedBy"
            :aria-invalid="error ? 'true' : undefined"
            :aria-required="required ? 'true' : undefined"
            @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
        >
            <option v-if="emptyLabel !== null" value="">{{ emptyLabel }}</option>
            <option v-for="option in options" :key="option.value" :value="option.value">
                {{ option.label }}
            </option>
        </select>

        <input
            v-else
            :id="controlId"
            class="form-control form-control-sm"
            :class="{ 'is-invalid': Boolean(error) }"
            :name="name"
            :type="type"
            :value="modelValue"
            :placeholder="placeholder"
            :required="required"
            :disabled="disabled"
            :autocomplete="autocomplete"
            :inputmode="inputmode ?? undefined"
            :aria-describedby="describedBy"
            :aria-invalid="error ? 'true' : undefined"
            :aria-required="required ? 'true' : undefined"
            @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
        />

        <p v-if="hint" :id="hintId" class="cdh-form-hint">{{ hint }}</p>

        <p v-if="error" :id="errorId" class="cdh-form-error">
            <i class="bi bi-exclamation-circle" aria-hidden="true" />
            <span>{{ error }}</span>
        </p>
    </div>
</template>
