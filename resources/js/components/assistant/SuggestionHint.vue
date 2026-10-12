<script setup lang="ts">
/**
 * The one suggestion worth showing, if there is one.
 *
 * ## Why this is a banner and not a modal
 *
 * A modal takes the screen away from the work someone was already doing, and it
 * has to be answered before they can get back to it. That is the wrong shape
 * for something that is, by design, usually wrong about when to speak: it would
 * make the assistant's rare good guesses expensive and its silence irrelevant.
 *
 * So it is inline, dismissible, and never blocks. The person reads it or they
 * do not, and either way they keep working.
 *
 * ## What is on the card
 *
 * The explanation, the action it proposes, and the evidence it is based on. The
 * evidence is not decoration: "Ana Torres tiene $45.000.000 en cartera y el 100%
 * ya está vencido" is a claim a collections operator is expected to act on, so
 * the figures behind it are shown rather than asked to be trusted.
 *
 * ## Nothing here writes
 *
 * Accepting tells the server the person chose to talk about this. The change
 * itself happens in the conversation it opens, through the ordinary confirmed
 * proposal flow.
 */
import { computed } from 'vue';

import type { AiSuggestionPayload } from '@/services/ai';

const props = defineProps<{
    suggestion: AiSuggestionPayload;
    /** True while an accept/dismiss request is in flight. */
    busy?: boolean;
}>();

const emit = defineEmits<{
    (e: 'accept', id: number): void;
    (e: 'dismiss', id: number): void;
}>();

/**
 * The figures behind the sentence, when they can be named.
 *
 * Deliberately a small fixed list rather than `Object.entries(evidence)`: the
 * evidence is the server's to shape, and a card that rendered whatever new key
 * arrived next would leak internal fields into the interface.
 */
const evidenceLines = computed<string[]>(() => {
    const evidence = props.suggestion.evidence ?? {};
    const lines: string[] = [];

    if (typeof evidence.client_name === 'string' && evidence.client_name !== '') {
        lines.push(`Cliente: ${evidence.client_name}`);
    }

    if (typeof evidence.outstanding_cop === 'number') {
        lines.push(`Saldo total: ${formatPesos(evidence.outstanding_cop)}`);
    }

    if (typeof evidence.overdue_cop === 'number') {
        lines.push(`Vencido: ${formatPesos(evidence.overdue_cop)}`);
    }

    if (typeof evidence.file_name === 'string' && evidence.file_name !== '') {
        lines.push(`Archivo: ${evidence.file_name}`);
    }

    if (typeof evidence.blocking_issues === 'number') {
        const issues = evidence.blocking_issues;
        lines.push(issues === 1 ? 'Problemas bloqueantes: 1' : `Problemas bloqueantes: ${issues}`);
    }

    if (typeof evidence.occurrences === 'number' && typeof evidence.distinct_imports === 'number') {
        lines.push(`Ocurrencias: ${evidence.occurrences} en ${evidence.distinct_imports} importaciones`);
    }

    return lines;
});

/** Whole pesos, grouped the Colombian way. Never abbreviated. */
function formatPesos(amount: number): string {
    return `$${amount.toLocaleString('es-CO', { maximumFractionDigits: 0 })}`;
}
</script>

<template>
    <aside
        class="rounded-lg border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-950"
        role="status"
        aria-live="polite"
        data-testid="suggestion-hint"
    >
        <p class="font-medium" data-testid="suggestion-explanation">
            {{ suggestion.explanation }}
        </p>

        <p v-if="suggestion.suggested_action" class="mt-1 text-indigo-800" data-testid="suggestion-action">
            {{ suggestion.suggested_action }}
        </p>

        <ul v-if="evidenceLines.length" class="mt-2 space-y-0.5 text-xs text-indigo-700" data-testid="suggestion-evidence">
            <li v-for="line in evidenceLines" :key="line">{{ line }}</li>
        </ul>

        <div class="mt-3 flex gap-2">
            <button
                type="button"
                class="rounded bg-indigo-600 px-3 py-1.5 font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                :disabled="busy"
                data-testid="suggestion-accept"
                @click="emit('accept', suggestion.id)"
            >
                Ver con el asistente
            </button>

            <button
                type="button"
                class="rounded border border-indigo-300 px-3 py-1.5 text-indigo-800 hover:bg-indigo-100 disabled:opacity-60"
                :disabled="busy"
                data-testid="suggestion-dismiss"
                @click="emit('dismiss', suggestion.id)"
            >
                Ahora no
            </button>
        </div>
    </aside>
</template>
