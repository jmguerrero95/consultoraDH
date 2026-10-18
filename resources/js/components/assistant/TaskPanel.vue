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
 * ## Scope: this thread's work, not every thread's
 *
 * The server answers with everything this person has, which is right for "did
 * my job finish" and wrong for the panel under a conversation: five documents
 * from three other threads sitting under the one being read is noise, and it
 * makes a finished job from elsewhere look like an answer to what was just
 * asked. So the panel shows the current conversation's work, its completed
 * history folded away, and the other threads behind a toggle that has to be
 * asked for. Nothing is hidden for good and nothing is deleted.
 *
 * ## What it deliberately does not do
 *
 * It does not offer a retry button for a failure. A retry here would need the
 * same idempotency guarantees as the original request to avoid duplicating work,
 * and until that exists the honest thing is to show the failure and let the
 * person go and do it again deliberately - which does create a new task, visibly.
 */
import { computed, ref } from 'vue';

import TaskRow from '@/components/assistant/TaskRow.vue';
import { useAiTasks } from '@/composables/useTasks';

/**
 * Takes the task controller from its parent rather than creating one.
 *
 * Two instances of this composable on one screen would be two lists polling the
 * same endpoint, and - worse - two independent views of the same work: a file
 * uploaded by the picker would appear in the parent's list and not in the
 * panel's, which is exactly the "I uploaded it and nothing happened" feeling
 * this feature exists to avoid.
 *
 * The fallback keeps the component usable on its own, and is what the unit
 * tests drive.
 *
 * `conversationId` is the thread the panel belongs to. When it is absent - the
 * standalone usage, and the page before a conversation exists - no scoping is
 * applied, which is the panel's original behaviour.
 */
const props = defineProps<{
    controller?: ReturnType<typeof useAiTasks>;
    conversationId?: number | null;
}>();

const own = props.controller === undefined ? useAiTasks() : null;

const { tasks, loading, refresh, cancel } = own ?? (props.controller as NonNullable<typeof props.controller>);

/** Whether the panel is scoped to one thread at all. */
const scoped = computed(() => props.conversationId !== undefined && props.conversationId !== null);

const currentTasks = computed(() =>
    scoped.value ? tasks.value.filter((task) => task.conversation_id === props.conversationId) : tasks.value,
);

/** Work still running in this thread: the reason the panel is on screen. */
const active = computed(() => currentTasks.value.filter((task) => !task.finished));

/** Work that already happened in this thread, folded away. */
const finished = computed(() => currentTasks.value.filter((task) => task.finished));

/** Work in other threads, shown only when asked for. */
const otherThreads = computed(() =>
    scoped.value ? tasks.value.filter((task) => task.conversation_id !== props.conversationId) : [],
);

const showOtherThreads = ref(false);

function onCancel(id: number): void {
    void cancel(id);
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

        <p v-if="currentTasks.length === 0" class="text-sm text-slate-500" data-testid="task-empty">
            No hay trabajos en curso.
        </p>

        <ul v-if="active.length" class="space-y-3" data-testid="task-active">
            <TaskRow v-for="task in active" :key="task.id" :task="task" @cancel="onCancel" />
        </ul>

        <!-- Completed work in this thread. Folded away while something is still
             running, so the panel leads with what is happening; shown directly
             when it is all this thread has, because then it is the answer to
             "did my job finish?" and hiding it behind a click would leave the
             panel looking empty. -->
        <details v-if="finished.length && active.length" class="mt-3" data-testid="task-history">
            <summary class="cursor-pointer text-xs text-slate-500">
                Trabajos completados ({{ finished.length }})
            </summary>
            <ul class="mt-3 space-y-3">
                <TaskRow v-for="task in finished" :key="task.id" :task="task" @cancel="onCancel" />
            </ul>
        </details>

        <div v-else-if="finished.length" class="mt-3" data-testid="task-history">
            <p class="text-xs text-slate-500">Trabajos completados ({{ finished.length }})</p>
            <ul class="mt-3 space-y-3">
                <TaskRow v-for="task in finished" :key="task.id" :task="task" @cancel="onCancel" />
            </ul>
        </div>

        <p v-if="active.length === 0 && finished.length > 0" class="mt-3 text-xs text-slate-500" data-testid="task-done">
            No hay trabajos pendientes.
        </p>

        <!-- Work from other threads. Behind a toggle: it belongs to this person
             and must stay reachable, but it is not this conversation's work. -->
        <div v-if="otherThreads.length" class="mt-3 border-t border-slate-100 pt-2">
            <button
                type="button"
                class="text-xs text-slate-600 underline hover:text-slate-800"
                data-testid="task-others-toggle"
                @click="showOtherThreads = !showOtherThreads"
            >
                {{ showOtherThreads ? 'Ocultar' : 'Ver' }} trabajos de otras conversaciones ({{ otherThreads.length }})
            </button>

            <ul v-if="showOtherThreads" class="mt-3 space-y-3" data-testid="task-others">
                <TaskRow v-for="task in otherThreads" :key="task.id" :task="task" @cancel="onCancel" />
            </ul>
        </div>
    </section>
</template>
