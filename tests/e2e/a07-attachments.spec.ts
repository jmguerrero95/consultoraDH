import { resolve } from 'node:path';

import { expect, test } from '@playwright/test';

import { apiRequest, signInAsStaff } from './support/a06';

/**
 * The workflow the integration exists for, in a real browser:
 *
 *   upload a spreadsheet through the visible picker → wait for the Redis worker
 *   → ask about a value that exists only in that file → get an answer built from
 *   that file, and not from any other thread.
 *
 * ## What is real here, and what is not
 *
 * Real: the visible file input, the upload, the Redis queue, the worker that
 * drains it, `DocumentIngestor`, the chunking and indexing, `DocumentRetriever`,
 * the conversation binding, and the HTTP contract of the endpoint.
 *
 * Replaced: the HTTP hop to the model. `ai-e2e` in `compose.e2e.yaml` answers
 * it deterministically, because the journey used to reach OpenRouter's free
 * tier and therefore failed whenever somebody else's daily allowance ran out —
 * a 500 that had nothing to do with attachments. Only `app-e2e` is pointed at
 * the stub; development and production still reach their real provider.
 *
 * ## Why this cannot pass by the provider being agreeable
 *
 * The stub answers from the request it receives: it looks for values that exist
 * inside `marcadores-e2e.xlsx` and nowhere else, and quotes back only the ones
 * it actually found in the retrieved passages. If the attachment went
 * unscoped, or retrieval returned nothing, or the wrong conversation were
 * asked, the value would not be in the request and the stub would say it found
 * nothing — and these assertions would fail. The marker has to travel the whole
 * way through the real pipeline to get here.
 *
 * ## Selecting the task by identity, not by position
 *
 * `/api/ai/tasks` is shared: every task this person has ever run, newest first,
 * capped at fifty. This used to read `tasks[0]`, which is a positional guess
 * that silently becomes somebody else's task the moment a journey before this
 * one queues something. The fixture's own filename is unique to this journey,
 * so the task is matched on that — a fact about the task, not about its place
 * in a list.
 */

/** The fixture's own name. Unique to this journey, so it identifies the task. */
const FIXTURE_NAME = 'marcadores-e2e.xlsx';

/**
 * The figure this journey asks for.
 *
 * It appears once in the fixture workbook, as the value beside "Saldo
 * consolidado", and nowhere in the application: no label, no code, no other
 * document. The label is asked for and the *value* must come back, which is
 * what makes it evidence that the file was read rather than that a model was
 * confident.
 */
const EXPECTED_VALUE = 'VALOR-ORIGEN-5501';

interface IngestTask {
    id: number;
    name: string | null;
    status: string;
    conversation_id: number | null;
    error: string | null;
    attempts: number;
    finished: boolean;
    result: { chunks?: number; characters?: number; attachment_id?: number } | null;
}

async function taskForFixture(page: import('@playwright/test').Page): Promise<IngestTask | null> {
    const listed = await apiRequest<{ tasks: IngestTask[] }>(page, 'GET', '/api/ai/tasks');
    const mine = listed.data.tasks.filter((task) => task.name === FIXTURE_NAME);

    // At most one: the fixture is uploaded once, by this journey. Two would
    // mean the name is no longer identifying anything.
    expect(mine.length, 'exactly one task should carry this fixture name').toBeLessThanOrEqual(1);

    return mine[0] ?? null;
}

test.describe('GATE 7: attached documents reach the assistant', () => {
    test.beforeEach(async ({ page }) => {
        await signInAsStaff(page);
    });

    test('a spreadsheet attached through the visible picker is answered from', async ({ page }) => {
        await page.goto('/asistente');

        // 1. The visible control, not the API.
        await page.setInputFiles(
            '[data-testid="attachment-input"]',
            resolve(process.cwd(), 'tests/e2e/fixtures/attachments/marcadores-e2e.xlsx'),
        );

        await expect(page.getByTestId('upload-error')).toHaveCount(0);

        // 2. A real worker, on a real queue, produced real indexed content.
        let task: IngestTask | null = null;

        await expect
            .poll(
                async () => {
                    task = await taskForFixture(page);

                    return task ? `${task.status}:${task.conversation_id ?? 'none'}:${task.error ?? ''}` : 'none';
                },
                { timeout: 120_000, intervals: [1_000, 2_000, 4_000] },
            )
            .toMatch(/^succeeded:[0-9]+:/);

        const settled = task!;

        // The work ran to completion on the queue rather than being faked:
        // it was attempted, it finished, and it left no error behind.
        expect(settled.attempts).toBeGreaterThanOrEqual(1);
        expect(settled.finished).toBe(true);
        expect(settled.error).toBeNull();

        // 3. Real content was indexed — chunks and characters, not a filename.
        expect(settled.result?.chunks ?? 0).toBeGreaterThan(0);
        expect(settled.result?.characters ?? 0).toBeGreaterThan(0);
        expect(settled.result?.attachment_id ?? 0).toBeGreaterThan(0);

        // 4. The attachment belongs to a conversation, and it is a real one.
        const conversationId = settled.conversation_id ?? 0;

        expect(conversationId).toBeGreaterThan(0);

        // 5. The person asks, and the answer is built from that document.
        const answer = await page.evaluate(
            async (input: { id: number; question: string }) => {
                const token = (document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '').replace(/%3D/g, '=');

                const response = await fetch('/api/ai/conversations', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token },
                    body: JSON.stringify({ conversation_id: input.id, question: input.question }),
                });

                return { status: response.status, body: await response.json() };
            },
            { id: conversationId, question: '¿Cuál es el saldo consolidado del documento adjunto?' },
        );

        // A 500 here is what the exhausted free tier used to produce; naming the
        // status makes that failure legible instead of a bare missing field.
        expect(answer.status, `the turn should be answered: ${JSON.stringify(answer.body)}`).toBe(200);

        // Answered inside the conversation the file was attached to, rather than
        // in a fresh one.
        expect(answer.body.conversation_id).toBe(conversationId);
        expect(typeof answer.body.reply).toBe('string');

        // The value from the spreadsheet, and not merely some text.
        expect(answer.body.reply).toContain(EXPECTED_VALUE);

        // 6. And the retrieval is scoped to that conversation.
        //
        // A fresh thread, asked the same question by the same person, must not
        // be able to reach this document. If the attachment had gone unscoped
        // this is where it would show: the value would leak into a thread that
        // never had the file.
        const elsewhere = await page.evaluate(
            async (question: string) => {
                const token = (document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '').replace(/%3D/g, '=');

                const response = await fetch('/api/ai/conversations', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token },
                    body: JSON.stringify({ question }),
                });

                return { status: response.status, body: await response.json() };
            },
            '¿Cuál es el saldo consolidado del documento adjunto?',
        );

        expect(elsewhere.status, `the second turn should be answered: ${JSON.stringify(elsewhere.body)}`).toBe(200);
        expect(elsewhere.body.conversation_id).not.toBe(conversationId);
        expect(elsewhere.body.reply).not.toContain(EXPECTED_VALUE);

        // The retrieval half at message level — that the passages from this very
        // file were delivered to the model, framed as data — is asserted where
        // it can be observed exactly, in `AttachmentToAssistantTest`: the
        // provider request is made by the PHP application, not by the browser,
        // so a Playwright route cannot see it and asserting on it here would be
        // asserting on nothing.
    });
});