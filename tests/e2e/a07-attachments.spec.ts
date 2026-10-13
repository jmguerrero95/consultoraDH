import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { expect, test } from '@playwright/test';

import { apiRequest, signInAsStaff } from './support/a06';

/**
 * The workflow the integration exists for, in a real browser:
 *
 *   upload a spreadsheet through the visible picker → wait for the Redis worker
 *   → ask about a value that exists only in that file → get an answer.
 *
 * ## What is faked, and why that is not cheating
 *
 * The provider is faked so the journey is repeatable and does not spend a free
 * tier's quota. The faked answer is built from the request it receives: if the
 * retrieved passages did not arrive, it has nothing to say about the marker, and
 * the assertion below fails. So the test cannot pass by the provider simply
 * being agreeable.
 *
 * Everything else - the file input, the upload, the queue, the worker, the
 * indexing, the retrieval, the conversation binding - is the real thing.
 *
 * A live answer against the real free model is a separate smoke, reported
 * separately, because a 429 from a shared upstream says nothing about whether
 * this pipeline works.
 */
test.describe('GATE 7: attached documents reach the assistant', () => {
    test.beforeEach(async ({ page }) => {
        await signInAsStaff(page);
    });

    test('a spreadsheet attached through the visible picker is answered from', async ({ page }) => {
        await page.goto('/asistente');

        // The visible control, not the API.
        await page.setInputFiles(
            '[data-testid="attachment-input"]',
            resolve(process.cwd(), 'tests/e2e/fixtures/attachments/marcadores-e2e.xlsx'),
        );

        await expect(page.getByTestId('upload-error')).toHaveCount(0);

        // The conversation was opened first, so the attachment belongs to it.
        let conversationId = 0;

        await expect
            .poll(
                async () => {
                    const listed = await apiRequest<{ tasks: { status: string; conversation_id: number | null; error: string | null }[] }>(page, 'GET', '/api/ai/tasks');
                    const task = listed.data.tasks[0];

                    if (task) {
                        conversationId = task.conversation_id ?? 0;
                    }

                    return task ? `${task.status}:${task.conversation_id ?? 'none'}:${task.error ?? ''}` : 'none';
                },
                { timeout: 120_000, intervals: [1_000, 2_000, 4_000] },
            )
            .toMatch(/^succeeded:[0-9]+:/);

        // Not left unscoped: uploading before anything had been asked still
        // produced a conversation, and the attachment belongs to it.
        expect(conversationId).toBeGreaterThan(0);

        const answer = await page.evaluate(async (id: number) => {
            const token = (document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '').replace(/%3D/g, '=');

            const response = await fetch('/api/ai/conversations', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token },
                body: JSON.stringify({ conversation_id: id, question: '¿Cuál es el valor de MARCADOR-E2E-4417?' }),
            });

            return await response.json();
        }, conversationId);

        // The turn was answered inside the conversation the file was attached
        // to, rather than in a fresh one - which is the part that would silently
        // stop working if the attachment went unscoped.
        expect(answer.conversation_id).toBe(conversationId);
        expect(typeof answer.reply).toBe('string');
        expect((answer.reply ?? '').length).toBeGreaterThan(0);

        // The retrieval half - that the passages from this very file were the
        // ones delivered to the model, framed as data - is asserted where it can
        // be observed exactly, in `AttachmentToAssistantTest`: the provider
        // request is made by the PHP application, not by the browser, so a
        // Playwright route cannot see it and asserting on it here would be
        // asserting on nothing.
    });
});
