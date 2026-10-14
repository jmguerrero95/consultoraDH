<script setup lang="ts">
import { ref, watch } from 'vue'
import { businessApi } from '@/services/api'

/**
 * Search a client by name or document and choose one.
 *
 * ## Why this exists
 *
 * Three screens asked an operator to type a client's **internal id** into a number field:
 * "Registrar novedad" wanted `Cliente (id)`, "Crear tarea" wanted `Cliente (id, opcional)`, and
 * "Adjuntar documento" popped `prompt('Id del cliente:')`. Nobody can look up a person's
 * payroll row from a number, so these forms were unusable for anybody who did not have the
 * database open.
 *
 * The backend contract is unchanged — it still wants an id — so this resolves a name or a
 * document into that id and hands back the same `v-model`.
 *
 * ## What it shows
 *
 * Both the name **and** the document, because "Julieth Santiago" is rarely unique and picking
 * the wrong one records a novelty or a task against the wrong person. When a search returns
 * nothing it says so, rather than leaving an empty list that looks like a broken form.
 *
 * ## Why the list loads on open
 *
 * An empty list until the operator typed is the behaviour this replaces elsewhere in the
 * application (see `PaymentListPage`): the field looked broken before it was ever used. The
 * first page is loaded when the picker is shown, and `per_page` is bounded, so this never
 * pulls the whole client table.
 */
// `defineProps` without an assignment: the template reads the props directly, so binding them
// to a local would only produce an unused variable.
withDefaults(
    defineProps<{
        modelValue: number | null
        label?: string
        optional?: boolean
        /** Pre-selected client, shown before any search — used for the task form. */
        selectedLabel?: string | null
    }>(),
    {
        label: 'Cliente',
        optional: false,
        selectedLabel: null,
    },
)

const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>()

interface ClientOption {
    id: number
    label: string
    document: string
}

const search = ref('')
const options = ref<ClientOption[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const touched = ref(false)

interface ClientSummary {
    id: number
    full_name?: string
    first_names?: string
    last_names?: string
    document_number?: string
    document_type?: string
}

/** "Nombre — CC 12345678": both halves, because a name alone is rarely unique. */
function describe(client: ClientSummary): string {
    const name = client.full_name ?? [client.first_names, client.last_names].filter(Boolean).join(' ')

    return [name, client.document_type ? `${client.document_type} ${client.document_number ?? ''}`.trim() : client.document_number]
        .filter(Boolean)
        .join(' — ')
}

async function runSearch(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        // A bounded page: the list is a picker, not a table, and loading every client to fill
        // a dropdown would be slower and leak more than it helps.
        const payload = await businessApi.clients.list({ search: search.value.trim(), per_page: 15 });

        options.value = payload.clients.map((client: ClientSummary) => ({
            id: client.id,
            label: describe(client),
            document: `${client.document_type ?? ''} ${client.document_number ?? ''}`.trim(),
        }));
    } catch {
        // An empty list here would be indistinguishable from "no matches", so the failure is
        // stated. Picking a client is never guessable from an error.
        options.value = [];
        error.value = 'No fue posible buscar clientes. Intente de nuevo.';
    } finally {
        loading.value = false;
    }
}

function choose(option: ClientOption): void {
    emit('update:modelValue', option.id);
    search.value = option.label;
    touched.value = false;
}

function clear(): void {
    emit('update:modelValue', null);
    search.value = '';
}

// Loaded as soon as the picker appears, so the form is usable without typing first.
watch(search, () => {
    touched.value = true;
    void runSearch();
}, { immediate: true });

defineExpose({ reload: runSearch });
</script>

<template>
    <div>
        <label class="form-label" :for="`client-picker-${label}`">{{ label }}</label>

        <input
            :id="`client-picker-${label}`"
            v-model="search"
            type="search"
            class="form-control"
            :placeholder="optional ? 'Buscar por nombre o documento…' : 'Buscar por nombre o documento…'"
            autocomplete="off"
        />

        <p v-if="loading" class="small text-muted mt-1 mb-0">Buscando…</p>
        <p v-else-if="error" class="small text-danger mt-1 mb-0">{{ error }}</p>
        <p v-else-if="touched && options.length === 0" class="small text-muted mt-1 mb-0">
            No se encontraron clientes con «{{ search }}».
        </p>

        <ul v-if="options.length > 0" class="list-group list-group-flush mt-1 client-picker__results">
            <li
                v-for="option in options"
                :key="option.id"
                class="list-group-item list-group-item-action py-2"
                :class="{ active: option.id === modelValue }"
                role="button"
                tabindex="0"
                @click="choose(option)"
                @keydown.enter.prevent="choose(option)"
                @keydown.space.prevent="choose(option)"
            >
                {{ option.label }}
            </li>
        </ul>

        <div v-if="modelValue !== null" class="form-text mt-1">
            Cliente seleccionado (id {{ modelValue }}).
            <button type="button" class="btn btn-link btn-sm p-0 align-baseline" @click="clear">
                Cambiar
            </button>
        </div>
    </div>
</template>

<style scoped>
.client-picker__results {
    max-height: 12rem;
    overflow-y: auto;
    border: 1px solid var(--bs-border-color, #dee2e6);
    border-radius: 0.375rem;
}
</style>