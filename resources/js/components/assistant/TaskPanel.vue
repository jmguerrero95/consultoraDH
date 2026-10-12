<script setup lang="ts">
/**
 * The work this person has waiting, and what became of it.
 *
 * ## Why this exists
 *
 * Background work outlives the tab that started it. Closing the app to save
 * battery does not cancel it, and should not make it invisible - otherwise the
 * natural reaction is to go and start the same job again, which is how somebody
 * ends up with three copies of a document and no idea which one is real.
 *
 * ## Why it is a panel and not a toast
 *
 * A toast disappears. This is a list of things that either happened or did not,
 * and somebody who closed the app for ten minutes needs to find out which.
 *
 * ## What it deliberately does not do
 *
 * It does not offer a retry button for a failure. A retry here would need the
 * same idempotency guarantees as the original request to avoid duplicating work,
 * and until that exists the honest thing is to show the failure and let the
 * person go and do it again deliberately - which does create a new task, visibly.
 */
import { computed } from 'vue';

import { useAiTasks } from '@/composables/useTasks';

const { tasks, pending, loading, refresh, cancel } = useAiTasks();

const finished = computed(() => tasks.value.filter((task) => task.finished));

function statusLabel(task: { status: string }): string {
    switch (task.status) {
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
            return task.status;
    }
}

/** Only a queued task can be cancelled; anything else is past that point. */
function canCancel(task: { status: string }): boolean {
    return task.status === 'queued';
}

function resultSummary(task: { result: Record<string, unknown> | null }): string | null {
    const result = task.result;

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
    <section class="rounded-lg border border-slate-200 bg-white p-4" data-testid="task-panel">
        <header class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-800">Trabajos en segundo plano</h2>

            <button
                type="button"
                class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                :disabled="loading"
                data-testid="task-refresh"
                @click="refresh"
            >
                Actualizar
            </button>
        </header>

        <p v-if="tasks.length === 0" class="text-sm text-slate-500" data-testid="task-empty">
            No hay trabajos en curso.
        </p>

        <ul v-else class="space-y-3">
            <li
                v-for="task in tasks"
                :key="task.id"
                class="rounded border border-slate-200 p-3"
                data-testid="task-row"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <span class="truncate text-sm font-medium text-slate-800" data-testid="task-name">
                        {{ task.name ?? 'Trabajo' }}
                    </span>

                    <span class="shrink-0 text-xs text-slate-500" data-testid="task-status">
                        {{ statusLabel(task) }}
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
                    {{ resultSummary(task) ?? 'Listo para consultar.' }}
                </p>

                <p v-if="task.error" class="mt-1 text-xs text-red-700" data-testid="task-error">
                    {{ task.error }}
                </p>

                <button
                    v-if="canCancel(task)"
                    type="button"
                    class="mt-2 rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50"
                    data-testid="task-cancel"
                    @click="cancel(task.id)"
                >
                    Cancelar
                </button>
            </li>
        </ul>

        <p v-if="pending().length === 0 && finished.length > 0" class="mt-3 text-xs text-slate-500" data-testid="task-done">
            No hay trabajos pendientes.
        </p>
    </section>
</template>
