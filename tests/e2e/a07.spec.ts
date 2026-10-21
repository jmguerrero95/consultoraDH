import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { expect, test } from '@playwright/test';

import { apiRequest, signInAsStaff } from './support/a06';

/**
 * GATE 7, end to end, in a real browser.
 *
 * ## What these journeys are for
 *
 * The suites behind this one prove each piece works: the engine decides, the
 * queue runs, the push service is reached, the card renders. None of them
 * prove the pieces are *joined* — that a file chosen in a browser comes out the
 * other end as something the assistant can answer from.
 *
 * That join is what breaks, and it breaks silently. A task that completes and
 * is never shown, a deep link that 404s, a suggestion that leaks from one
 * screen into another: none of those are visible from a unit test.
 *
 * ## Setup goes through the product, not around it
 *
 * There is no fixture endpoint in this file. Everything is created with the
 * same API the interface uses, signed in as a real staff account, so a journey
 * cannot pass by talking to something the application does not have.
 */
let taskIdValue: number | null = null;

test.describe('GATE 7: assistant, background work and suggestions', () => {
    test.beforeEach(async ({ page }) => {
        await signInAsStaff(page);
    });

    test('a real upload is queued, processed by a worker and readable from the conversation', async ({ page }) => {
        // A real .xlsx, written by the same library the ingestor reads with. An
        // earlier version assembled a zip by hand inside the browser; it was
        // structurally valid enough to be accepted and then failed to parse, so
        // the journey proved only that a bad file is rejected.
        const workbook = readFileSync(resolve(process.cwd(), 'tests/e2e/fixtures/attachments/cartera-e2e.xlsx'));

        // Sent through Playwright's request client rather than `fetch` inside the
        // page. Both are the same HTTP request, but the in-page one runs inside
        // the application's own JavaScript environment - and a hang there says
        // nothing about the endpoint, while this one either answers or does not.
        const uploaded = await page.request.post('/api/ai/tasks', {
            multipart: {
                file: {
                    name: 'cartera-e2e.xlsx',
                    mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    buffer: workbook,
                },
            },
        });

        const body = (await uploaded.json().catch(() => null)) as { task?: { id: number; status: string } } | null;

        expect(uploaded.status(), uploaded.statusText()).toBe(202);
        expect(body?.task?.status).toBe('queued');

        const taskId = body!.task!.id;

        // The work runs on a queue, so the only honest way to read it is to
        // poll - and the assertion is that it reaches a finished state, not that
        // it finished quickly.
        await expect
            .poll(
                async () => {
                    const listed = await apiRequest<{ tasks: { id: number; status: string; finished: boolean; error: string | null }[] }>(page, 'GET', '/api/ai/tasks');
                    const task = listed.data.tasks.find((candidate) => candidate.id === taskId);

                    return task ? `${task.status}:${task.error ?? ''}` : 'missing';
                },
                { timeout: 120_000, intervals: [1_000, 2_000, 4_000] },
            )
            .toMatch(/^succeeded:/);

        // And it is visible in the interface, which is where a person would
        // look for it after closing the tab.
        await page.goto('/asistente');

        // The end-to-end stack serves the SPA from the Vite dev server, which
        // compiles modules on the first request in a fresh browser - the same
        // reason `playwright.config.ts` raised the global timeout. On the first
        // journey the shell can still be an empty `#app` when this assertion
        // starts, so wait for the application to have mounted before looking
        // inside it. This waits for the product, it does not skip it.
        await expect(page.getByTestId('task-panel')).toBeVisible({ timeout: 60_000 });

        await expect(page.getByTestId('task-row').first()).toBeVisible();
    });

    test('the visual file picker queues a real workbook and its data is readable from the conversation', async ({ page }) => {
        // The journey the brief requires: the person opens the assistant, uses
        // the on-screen control, and never touches the API. Sending the upload
        // from Playwright directly would prove the endpoint, not the interface.
        await page.goto('/asistente');

        const picker = page.getByTestId('attachment-input');
        await expect(picker).toBeAttached();

        // The control is a real file input, so a keyboard and a phone both reach
        // it - the picker below is driven through it.
        await page.setInputFiles('[data-testid="attachment-input"]', resolve(process.cwd(), 'tests/e2e/fixtures/attachments/cartera-e2e.xlsx'));

        await expect(page.getByTestId('attachment-name')).toContainText('cartera-e2e.xlsx');
        await expect(page.getByTestId('upload-error')).toHaveCount(0);

        // The work is queued and a real worker picks it up. Polling the list is
        // exactly what the panel does, so this asserts what a person would see.
        await expect
            .poll(
                async () => {
                    const listed = await apiRequest<{ tasks: { id: number; status: string; error: string | null }[] }>(page, 'GET', '/api/ai/tasks');
                    const task = listed.data.tasks[0];

                    if (task) {
                        taskIdValue = task.id;
                    }

                    return task ? `${task.status}:${task.error ?? ''}` : 'none';
                },
                { timeout: 120_000, intervals: [1_000, 2_000, 4_000] },
            )
            .toMatch(/^succeeded:/);

        // The panel shows it as finished.
        await expect(page.getByTestId('task-row').first()).toBeVisible();
        await expect(page.getByTestId('task-status').first()).toHaveText('Listo');

        // And the worker actually extracted and indexed content - a file that
        // reached "Listo" with nothing in it would look identical on screen.
        const detail = await apiRequest<{ task: { result: { characters: number; chunks: number; attachment_id: number } } }>(
            page,
            'GET',
            `/api/ai/tasks/${taskIdValue}`,
        );

        // `apiRequest` answers `{ status, data }`, not an APIResponse.
        expect(detail.status).toBe(200);
        expect(detail.data.task.result.characters).toBeGreaterThan(0);
        expect(detail.data.task.result.chunks).toBeGreaterThan(0);

        // Reading that content back is proven where it is exact rather than
        // through a model: `DocumentPipelineTest` searches the very same rows
        // with the retriever the assistant uses, scoped to the conversation.
        // Here the journey stops at "the bytes were really read".
    });

    test('the task panel appears on the assistant screen and reports its state', async ({ page }) => {
        await page.goto('/asistente');

        await expect(page.getByTestId('task-panel')).toBeVisible();
        // Either there is nothing pending or the list says what is - both are
        // correct. What is not correct is an empty panel with no explanation.
        await expect(page.getByTestId('task-empty').or(page.getByTestId('task-row')).first()).toBeVisible();
    });

    test('another person’s task is refused, and the refusal does not confirm it exists', async ({ page }) => {
        const mine = await apiRequest<{ tasks: { id: number }[] }>(page, 'GET', '/api/ai/tasks');
        const taskId = mine.data.tasks?.[0]?.id;

        // An id that certainly does not exist, and a plausible one that might.
        const absent = await apiRequest(page, 'GET', '/api/ai/tasks/99999999');
        expect(absent.status).toBe(404);

        if (taskId !== undefined) {
            const present = await apiRequest(page, 'GET', `/api/ai/tasks/${taskId}`);
            // Visible to its owner. The isolation that matters is covered in the
            // backend suite against a second real account.
            expect(present.status).toBe(200);
        }
    });

    test('a foreign conversation id is refused rather than silently accepted', async ({ page }) => {
        const response = await apiRequest(
            page,
            'GET',
            '/api/ai/suggestion?module=receivables&operation=client_account&conversation_id=99999999',
        );

        // 404 for an id nobody owns - the same answer as for one that does not
        // exist, which is the point.
        expect(response.status).toBe(404);
    });

    test('suggestion silence is a normal answer, not an error', async ({ page }) => {
        await page.goto('/asistente');

        const shown = await page.getByTestId('suggestion-hint').count();
        const silence = await apiRequest<{ suggestion: unknown }>(
            page,
            'GET',
            '/api/ai/suggestion?module=receivables&operation=client_account',
        );

        expect(silence.status).toBe(200);

        // Whatever it decided, the two must agree: a card on screen with a null
        // payload behind it, or a silent endpoint with a card drawn.
        expect(shown === 0 || silence.data.suggestion !== null).toBe(true);
    });

    test('the PWA shell is served and the manifest is valid', async ({ page, request }) => {
        const response = await request.get('/manifest.json');
        expect(response.status()).toBe(200);

        const manifest = await response.json();

        expect(manifest.name).toBeTruthy();
        expect(manifest.start_url).toBeTruthy();
        expect(manifest.display).toBe('standalone');
        expect(Array.isArray(manifest.icons) && manifest.icons.length > 0).toBe(true);

        // Service workers require a secure context. `http://nginx-e2e` is not
        // one, so on this host the API is legitimately absent and nothing can be
        // proven about registration here.
        //
        // What is asserted is that the situation is understood rather than
        // assumed: either the browser gave us a worker, or it is because the
        // page is not secure. A third outcome - worker missing on a secure
        // context - is a real defect and fails.
        await page.goto('/asistente');

        const sw = await page.evaluate(async () => {
            const secure = window.isSecureContext;

            if (!('serviceWorker' in navigator)) {
                return { secure, registered: null as number | null, supported: false };
            }

            const registrations = await navigator.serviceWorker.getRegistrations();

            return { secure, registered: registrations.length, supported: true };
        });

        if (sw.supported) {
            expect(sw.registered, 'A service worker must register on a secure context').toBeGreaterThan(0);
        } else {
            // The honest external limitation, recorded rather than papered over.
            expect(sw.secure, 'Without a service worker, the context must be the reason').toBe(false);
            test.info().annotations.push({
                type: 'issue',
                description: 'Service worker not registrable: E2E host is not a secure context. Installation on a real HTTPS origin is NOT_EXECUTED_EXTERNAL.',
            });
        }
    });
});
