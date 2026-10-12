import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';

import TaskPanel from '@/components/assistant/TaskPanel.vue';
import { useConnectivity } from '@/composables/useTasks';
import { useAiTasks } from '@/composables/useTasks';
import { ai } from '@/services/ai';
import type { AiTaskPayload } from '@/services/ai';

/**
 * GATE 4: the PWA client.
 *
 * The properties worth protecting are mostly about honesty when things go
 * wrong. A background job that is still running must not be reported as failed
 * because the phone lost signal; a request the server rejected must not be
 * retried forever; and a page that has lost the network must say so without
 * pretending to know why.
 */

function task(overrides: Partial<AiTaskPayload> = {}): AiTaskPayload {
    return {
        id: 1,
        kind: 'document_ingest',
        status: 'running',
        progress: 40,
        stage: 'Extrayendo texto',
        name: 'notas.txt',
        result: null,
        error: null,
        attempts: 1,
        finished: false,
        recoverable: false,
        conversation_id: null,
        created_at: '2026-06-01 09:00:00',
        finished_at: null,
        ...overrides,
    };
}

/** Mounts a composable inside a component so its lifecycle hooks run. */
function withComposable<T>(composable: () => T): [T, ReturnType<typeof mount>] {
    let result!: T;
    const wrapper = mount(
        defineComponent({
            setup() {
                result = composable();

                return () => h('div');
            },
        }),
    );

    return [result, wrapper];
}

describe('useConnectivity', () => {
    it('treats a failed request as the real signal, not the browser flag', () => {
        // `navigator.onLine` is true in a hotel wifi that cannot reach anything.
        const [connectivity] = withComposable(() => useConnectivity());

        expect(connectivity.isOffline()).toBe(false);

        connectivity.noteFailure(0);
        expect(connectivity.isOffline()).toBe(true);
    });

    it('does not call a rejected request "offline"', () => {
        // A 422 is the server answering. Showing a connectivity banner to
        // somebody who mistyped a field would be actively misleading.
        const [connectivity] = withComposable(() => useConnectivity());

        connectivity.noteFailure(422);
        expect(connectivity.isOffline()).toBe(false);
    });

    it('treats a server error as unreachable', () => {
        const [connectivity] = withComposable(() => useConnectivity());

        connectivity.noteFailure(503);
        expect(connectivity.isOffline()).toBe(true);

        connectivity.noteSuccess();
        expect(connectivity.isOffline()).toBe(false);
    });
});

describe('useAiTasks', () => {
    it('loads what is waiting and reports nothing when there is nothing', async () => {
        vi.spyOn(ai, 'tasks').mockResolvedValue({ tasks: [] });

        const [tasks] = withComposable(() => useAiTasks());
        await nextTick();
        await Promise.resolve();

        expect(tasks.tasks.value).toEqual([]);
        expect(tasks.pending()).toEqual([]);
    });

    it('shows work that is still running, with its progress', async () => {
        vi.spyOn(ai, 'tasks').mockResolvedValue({ tasks: [task()] });

        const [tasks] = withComposable(() => useAiTasks());
        await nextTick();
        await Promise.resolve();

        expect(tasks.pending()).toHaveLength(1);
        expect(tasks.tasks.value[0].progress).toBe(40);
    });

    it('stays quiet about an error instead of claiming the work failed', async () => {
        // The job is on the server and still running. This view failing to load
        // says nothing about the job.
        vi.spyOn(ai, 'tasks').mockRejectedValue(new Error('offline'));

        const [tasks] = withComposable(() => useAiTasks());
        await nextTick();
        await Promise.resolve();

        expect(tasks.error.value).toBe('offline');
        expect(tasks.tasks.value).toEqual([]);
    });

    it('cancels through the service and reloads', async () => {
        vi.spyOn(ai, 'tasks').mockResolvedValue({ tasks: [task()] });
        const cancel = vi.spyOn(ai, 'cancelTask').mockResolvedValue({ task: task({ status: 'cancelled', finished: true }) });

        const [tasks] = withComposable(() => useAiTasks());
        await nextTick();
        await Promise.resolve();

        await tasks.cancel(1);

        expect(cancel).toHaveBeenCalledWith(1);
    });

    it('does not poll when nothing is in flight', async () => {
        vi.spyOn(ai, 'tasks').mockResolvedValue({ tasks: [task({ status: 'succeeded', finished: true })] });

        const [, wrapper] = withComposable(() => useAiTasks());
        await nextTick();
        await Promise.resolve();

        const callsAfterLoad = vi.mocked(ai.tasks).mock.calls.length;

        // An idle page making requests forever is a battery bug, not a feature.
        await new Promise((resolve) => setTimeout(resolve, 60));
        expect(vi.mocked(ai.tasks).mock.calls.length).toBe(callsAfterLoad);

        wrapper.unmount();
    });
});

describe('TaskPanel', () => {
    it('says plainly that there is nothing pending', async () => {
        vi.spyOn(ai, 'tasks').mockResolvedValue({ tasks: [] });

        const wrapper = mount(TaskPanel);
        await nextTick();
        await Promise.resolve();
        await nextTick();

        expect(wrapper.find('[data-testid="task-empty"]').exists()).toBe(true);
    });

    it('shows a running job with its progress and stage', async () => {
        vi.spyOn(ai, 'tasks').mockResolvedValue({ tasks: [task()] });

        const wrapper = mount(TaskPanel);
        await nextTick();
        await Promise.resolve();
        await nextTick();

        expect(wrapper.get('[data-testid="task-name"]').text()).toBe('notas.txt');
        expect(wrapper.get('[data-testid="task-status"]').text()).toBe('En proceso');
        expect(wrapper.get('[data-testid="task-stage"]').text()).toContain('Extrayendo texto');
        expect(wrapper.get('[role="progressbar"]').attributes('aria-valuenow')).toBe('40');
    });

    it('shows the failure reason rather than a generic error', async () => {
        vi.spyOn(ai, 'tasks').mockResolvedValue({
            tasks: [task({ status: 'failed', finished: true, recoverable: true, error: 'El archivo ya no está en el almacenamiento.' })],
        });

        const wrapper = mount(TaskPanel);
        await nextTick();
        await Promise.resolve();
        await nextTick();

        expect(wrapper.get('[data-testid="task-error"]').text()).toBe('El archivo ya no está en el almacenamiento.');
    });

    it('offers cancellation only for work that has not started', async () => {
        vi.spyOn(ai, 'tasks').mockResolvedValue({
            tasks: [
                task({ id: 1, status: 'queued' }),
                task({ id: 2, status: 'running' }),
                task({ id: 3, status: 'succeeded', finished: true }),
            ],
        });

        const wrapper = mount(TaskPanel);
        await nextTick();
        await Promise.resolve();
        await nextTick();

        // Cancelling a running job would abandon a half-written index, so the
        // control is only there while stopping is honest.
        expect(wrapper.findAll('[data-testid="task-cancel"]')).toHaveLength(1);
    });

    it('summarises a finished document without dumping the result', async () => {
        vi.spyOn(ai, 'tasks').mockResolvedValue({
            tasks: [
                task({
                    status: 'succeeded',
                    finished: true,
                    result: { attachment_id: 7, characters: 45012, chunks: 38, storage_path: 'secret/path' },
                }),
            ],
        });

        const wrapper = mount(TaskPanel);
        await nextTick();
        await Promise.resolve();
        await nextTick();

        const text = wrapper.get('[data-testid="task-result"]').text();
        expect(text).toContain('38');
        expect(text).toContain('45.012');
        expect(wrapper.text()).not.toContain('secret/path');
    });
});
