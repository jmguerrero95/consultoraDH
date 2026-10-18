<script setup lang="ts">
/**
 * One row of the background-work panel.
 *
 * Extracted so the current conversation's work, its completed history and the
 * optional view of other threads all render a task the same way. Three copies of
 * this markup would eventually disagree, and the one that disagreed would be the
 * one nobody was looking at.
 */
import type { AiTaskPayload } from '@/services/ai';

defineProps<{ task: AiTaskPayload }>();

defineEmits<{ cancel: [id: number] }>();

function statusLabel(status: string): string {
    switch (status) {
        case 'queued':
            return 'En cola';
        case 'running':
            return 'En proceso';
        case 'succeeded':
            return 'Listo';
        case 'failed':
            return 'Falló';
        case 'cancelled':
            return 'Cancelado';
        default:
            return status;
    }
}

/** Only a queued task can be cancelled; anything else is past that point. */
function canCancel(status: string): boolean {
    return status === 'queued';
}

function resultSummary(result: Record<string, unknown> | null): string | null {
    if (!result) {
        return null;
    }

    if (typeof result.chunks === 'number' && typeof result.characters === 'number') {
        // Grouped the Colombian way, like every other figure in the assistant.
        // A raw `45012` next to a `$45.000.000` elsewhere on the same screen
        // reads as a different quantity to anybody who has seen either before.
        return `${result.chunks} fragmentos · ${result.characters.toLocaleString('es-CO')} caracteres`;
    }

    return null;
}
</script>

<template>
    <li class="rounded border border-slate-200 p-3" data-testid="task-row">
        <div class="flex items-baseline justify-between gap-2">
            <span class="truncate text-sm font-medium text-slate-800" data-testid="task-name">
                {{ task.name ?? 'Trabajo' }}
            </span>

            <span class="shrink-0 text-xs text-slate-500" data-testid="task-status">
                {{ statusLabel(task.status) }}
            </span>
        </div>

        <div
            v-if="!task.finished"
            class="mt-2 h-1.5 w-full overflow-hidden rounded bg-slate-200"
            role="progressbar"
            :aria-valuenow="task.progress"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="task.name ?? 'Trabajo'"
        >
            <div class="h-full bg-indigo-600 transition-all" :style="{ width: `${task.progress}%` }"></div>
        </div>

        <p v-if="task.stage && !task.finished" class="mt-1 text-xs text-slate-500" data-testid="task-stage">
            {{ task.stage }} · {{ task.progress }}%
        </p>

        <p v-if="task.status === 'succeeded'" class="mt-1 text-xs text-emerald-700" data-testid="task-result">
            {{ resultSummary(task.result) ?? 'Listo para consultar.' }}
        </p>

        <p v-if="task.error" class="mt-1 text-xs text-red-700" data-testid="task-error">
            {{ task.error }}
        </p>

        <button
            v-if="canCancel(task.status)"
            type="button"
            class="mt-2 rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50"
            data-testid="task-cancel"
            @click="$emit('cancel', task.id)"
        >
            Cancelar
        </button>
    </li>
</template>
