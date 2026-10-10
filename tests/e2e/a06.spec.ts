import { expect, test } from '@playwright/test';
import { loginAsClientA, loginAsClientB, loginAsStaff } from './support/a06';

/* -------------------------------------------------------------------------- */
/* FLOW A — CLIENT / STAFF / CLIENT ROUNDTRIP                                 */
/* -------------------------------------------------------------------------- */

test('A. client message starts first-response clock', async ({ page }) => {
    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('Roundtrip test');
    await page.getByLabel('Mensaje').fill('Necesito ayuda');
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();

    await loginAsStaff(page);
    await page.goto('/soporte');
    await page.getByRole('button', { name: 'Ver conversación' }).first().click();
    await page.getByRole('textbox', { name: /Mensaje/ }).fill('Ya le estamos mirando');
    await page.getByRole('button', { name: 'Enviar respuesta' }).click();

    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await expect(page.getByText('Ya le estamos mirando')).toBeVisible();
});

test('A. staff reply satisfies first clock and starts next', async ({ page }) => {
    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('Next clock test');
    await page.getByLabel('Mensaje').fill('Need help');
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();

    await loginAsStaff(page);
    await page.goto('/soporte');
    await page.getByRole('button', { name: 'Ver conversación' }).first().click();
    await page.getByRole('textbox', { name: /Mensaje/ }).fill('Staff reply');
    await page.getByRole('button', { name: 'Enviar respuesta' }).click();

    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await expect(page.getByText('Staff reply')).toBeVisible();
});

test('A. internal note does not satisfy first response clock', async ({ page }) => {
    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('Note test');
    await page.getByLabel('Mensaje').fill('Internal note');
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();

    await loginAsStaff(page);
    await page.goto('/soporte');
    await page.getByRole('button', { name: 'Ver conversación' }).first().click();
    await page.getByRole('button', { name: 'Añadir nota interna' }).click();
    await page.getByLabel('Cuerpo de la nota').fill('Solo personal');
    await page.getByRole('button', { name: 'Guardar nota' }).click();

    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await expect(page.getByText('Solo personal')).not.toBeVisible();
});

/* -------------------------------------------------------------------------- */
/* FLOW B — CROSS-CLIENT ISOLATION                                            */
/* -------------------------------------------------------------------------- */

test('B. client B cannot access client A conversation', async ({ page }) => {
    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('A-isolated');
    await page.getByLabel('Mensaje').fill('From A');
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();

    await loginAsClientB(page);
    await page.goto('/portal/soporte');
    await expect(page.getByText('A-isolated')).not.toBeVisible();
});

test('B. staff cannot mix clients across conversations', async ({ page }) => {
    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('Isolation test');
    await page.getByLabel('Mensaje').fill('Staff test');
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();

    await loginAsStaff(page);
    await page.goto('/soporte');
    await expect(page.getByText('Isolation test')).toBeVisible();
});

/* -------------------------------------------------------------------------- */
/* FLOW C — INTERNAL NOTE PRIVACY                                             */
/* -------------------------------------------------------------------------- */

test('C. internal note invisible to portal client', async ({ page }) => {
    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('Privacy test');
    await page.getByLabel('Mensaje').fill('Client message');
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();

    await loginAsStaff(page);
    await page.goto('/soporte');
    await page.getByRole('button', { name: 'Ver conversación' }).first().click();
    await page.getByRole('button', { name: 'Añadir nota interna' }).click();
    await page.getByLabel('Cuerpo de la nota').fill('Solo el personal ve esto');
    await page.getByRole('button', { name: 'Guardar nota' }).click();

    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await expect(page.getByText('Solo el personal ve esto')).not.toBeVisible();
});

/* -------------------------------------------------------------------------- */
/* FLOW D — SUPPORT OPERATIONS                                                */
/* -------------------------------------------------------------------------- */

test('D. staff assignment changes queue', async ({ page }) => {
    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('Assignment test');
    await page.getByLabel('Mensaje').fill('Assign me');
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();

    await loginAsStaff(page);
    await page.goto('/soporte');
    await page.getByRole('button', { name: 'Ver conversación' }).first().click();
    await page.getByRole('select', { name: /queue/i }).selectOption('general');
    await page.getByRole('button', { name: 'Guardar cambios' }).click();

    await loginAsClientA(page);
    await page.goto('/portal/soporte');
});

test('D. staff resolves conversation', async ({ page }) => {
    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('Resolve test');
    await page.getByLabel('Mensaje').fill('Will resolve');
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();

    await loginAsStaff(page);
    await page.goto('/soporte');
    await page.getByRole('button', { name: 'Ver conversación' }).first().click();
    await page.getByRole('button', { name: 'Resolver' }).click();

    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await expect(page.getByText('La conversación ya está cerrada')).toBeVisible();
});

test('D. unauthorized staff cannot resolve conversation', async ({ page }) => {
    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('Unauthorized resolve');
    await page.getByLabel('Mensaje').fill('Test');
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();

    await loginAsStaff(page);
    await page.goto('/soporte');
    await page.getByRole('button', { name: 'Ver conversación' }).first().click();
    await expect(page.getByRole('button', { name: 'Resolver' })).not.toBeVisible();
});

/* -------------------------------------------------------------------------- */
/* FLOW E — FILE/AUDIO ATTACHMENTS                                            */
/* -------------------------------------------------------------------------- */

test('E. upload attachment and send with message', async ({ page }) => {
    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('Attachment test');
    await page.getByLabel('Mensaje').fill('Message with file');
    
    const fileInput = page.locator('input[type="file"]').first();
    await fileInput.setInputFiles({
        name: 'test.txt',
        mimeType: 'text/plain',
        buffer: Buffer.from('test file content'),
    });
    
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();
    await expect(page.getByText('test.txt')).toBeVisible();
});

test('E. audio attachment contract', async ({ page }) => {
    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('Audio test');
    await page.getByLabel('Mensaje').fill('Audio message');
    
    const fileInput = page.locator('input[type="file"]').first();
    await fileInput.setInputFiles({
        name: 'audio.webm',
        mimeType: 'audio/webm',
        buffer: Buffer.from('fake audio data'),
    });
    
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();
    await expect(page.getByText('audio.webm')).toBeVisible();
});

test('E. foreign client denied attachment download', async ({ page }) => {
    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('Attachment download test');
    await page.getByLabel('Mensaje').fill('File for A');
    
    const fileInput = page.locator('input[type="file"]').first();
    await fileInput.setInputFiles({
        name: 'secret.txt',
        mimeType: 'text/plain',
        buffer: Buffer.from('secret'),
    });
    
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();
    
    await loginAsClientB(page);
    await page.goto('/portal/soporte');
    await expect(page.getByText('Attachment download test')).not.toBeVisible();
});

/* -------------------------------------------------------------------------- */
/* FLOW F — BIDIRECTIONAL EMAIL                                               */
/* -------------------------------------------------------------------------- */

test('F. staff reply generates fallback email with correct reply-to', async ({ page, request }) => {
    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('Email roundtrip');
    await page.getByLabel('Mensaje').fill('Hola, necesidad de soporte');
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();

    await loginAsStaff(page);
    await page.goto('/soporte');
    await page.getByRole('button', { name: 'Ver conversación' }).first().click();
    await page.getByRole('textbox', { name: /Mensaje/ }).fill('Respuesta del staff');
    await page.getByRole('button', { name: 'Enviar respuesta' }).click();

    const emailResp = await request.get('/api/support/email-last');
    expect(emailResp.status()).toBe(200);
    const body = await emailResp.json();
    expect(body).toHaveProperty('replyTo');
    expect(body.conversationId).toBeDefined();
});

test('F. client email reply appears in same conversation', async ({ page, request }) => {
    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('Email reply test');
    await page.getByLabel('Mensaje').fill('Original message');
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();

    await loginAsStaff(page);
    await page.goto('/soporte');
    await page.getByRole('button', { name: 'Ver conversación' }).first().click();
    await page.getByRole('textbox', { name: /Mensaje/ }).fill('Staff reply for email');
    await page.getByRole('button', { name: 'Enviar respuesta' }).click();

    const emailResp = await request.get('/api/support/email-last');
    const body = await emailResp.json();
    const replyTo = body.replyTo;
    const conversationId = body.conversationId;

    expect(replyTo).toBeDefined();
    expect(conversationId).toBeDefined();

    const tokenMatch = replyTo.match(/token=([^&]+)/);
    expect(tokenMatch).not.toBeNull();
    const rawToken = tokenMatch![1];

    const hmacSecret = process.env.SUPPORT_EMAIL_HMAC_SECRET ?? 'test-secret';
    const timestamp = Math.floor(Date.now() / 1000);
    const payload = `${rawToken}|${timestamp}`;
    const crypto = await import('crypto');
    const hmac = crypto.createHmac('sha256', hmacSecret).update(payload).digest('hex');

    const emailBody = {
        token: rawToken,
        timestamp,
        hmac,
        from: 'e2e-client-a@consultora-dh.test',
        subject: 'Re: Email reply test',
        text: 'This is a client reply via email',
        message_id: `<${Date.now()}.${stamp}@e2e.test>`,
    };

    const inboundResp = await request.post('/api/internal/support-email/inbound', {
        data: emailBody,
        headers: {
            'Content-Type': 'application/json',
            'X-Support-Email-Signature': hmac,
            'X-Support-Email-Timestamp': String(timestamp),
        },
    });

    expect(inboundResp.status()).toBe(200);

    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await expect(page.getByText('This is a client reply via email')).toBeVisible();
});

test('F. duplicate email reply creates no duplicate', async ({ page, request }) => {
    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('Duplicate email test');
    await page.getByLabel('Mensaje').fill('Original');
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();

    await loginAsStaff(page);
    await page.goto('/soporte');
    await page.getByRole('button', { name: 'Ver conversación' }).first().click();
    await page.getByRole('textbox', { name: /Mensaje/ }).fill('Staff reply');
    await page.getByRole('button', { name: 'Enviar respuesta' }).click();

    const emailResp = await request.get('/api/support/email-last');
    const body = await emailResp.json();
    const replyTo = body.replyTo;
    const tokenMatch = replyTo.match(/token=([^&]+)/);
    const rawToken = tokenMatch![1];

    const hmacSecret = process.env.SUPPORT_EMAIL_HMAC_SECRET ?? 'test-secret';
    const timestamp = Math.floor(Date.now() / 1000);
    const payload = `${rawToken}|${timestamp}`;
    const crypto = await import('crypto');
    const hmac = crypto.createHmac('sha256', hmacSecret).update(payload).digest('hex');

    const emailBody = {
        token: rawToken,
        timestamp,
        hmac,
        from: 'e2e-client-a@consultora-dh.test',
        subject: 'Re: Duplicate email test',
        text: 'Duplicate reply',
        message_id: `<${Date.now()}.dup@e2e.test>`,
    };

    await request.post('/api/internal/support-email/inbound', {
        data: emailBody,
        headers: {
            'Content-Type': 'application/json',
            'X-Support-Email-Signature': hmac,
            'X-Support-Email-Timestamp': String(timestamp),
        },
    });

    await request.post('/api/internal/support-email/inbound', {
        data: emailBody,
        headers: {
            'Content-Type': 'application/json',
            'X-Support-Email-Signature': hmac,
            'X-Support-Email-Timestamp': String(timestamp),
        },
    });

    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    const messages = page.getByText('Duplicate reply');
    await expect(messages).toHaveCount(1);
});

/* -------------------------------------------------------------------------- */
/* FLOW G — QUARANTINE                                                        */
/* -------------------------------------------------------------------------- */

test('G. wrong sender email goes to quarantine', async ({ page, request }) => {
    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('Quarantine test');
    await page.getByLabel('Mensaje').fill('Original');
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();

    await loginAsStaff(page);
    await page.goto('/soporte');
    await page.getByRole('button', { name: 'Ver conversación' }).first().click();
    await page.getByRole('textbox', { name: /Mensaje/ }).fill('Staff reply');
    await page.getByRole('button', { name: 'Enviar respuesta' }).click();

    const emailResp = await request.get('/api/support/email-last');
    const body = await emailResp.json();
    const replyTo = body.replyTo;
    const tokenMatch = replyTo.match(/token=([^&]+)/);
    const rawToken = tokenMatch![1];

    const hmacSecret = process.env.SUPPORT_EMAIL_HMAC_SECRET ?? 'test-secret';
    const timestamp = Math.floor(Date.now() / 1000);
    const payload = `${rawToken}|${timestamp}`;
    const crypto = await import('crypto');
    const hmac = crypto.createHmac('sha256', hmacSecret).update(payload).digest('hex');

    const emailBody = {
        token: rawToken,
        timestamp,
        hmac,
        from: 'attacker@evil.com',
        subject: 'Re: Quarantine test',
        text: 'Malicious reply',
        message_id: `<${Date.now()}.quarantine@e2e.test>`,
    };

    const inboundResp = await request.post('/api/internal/support-email/inbound', {
        data: emailBody,
        headers: {
            'Content-Type': 'application/json',
            'X-Support-Email-Signature': hmac,
            'X-Support-Email-Timestamp': String(timestamp),
        },
    });

    expect(inboundResp.status()).toBe(200);
    const result = await inboundResp.json();
    expect(result.status).toBe('quarantined');
    expect(result.reason).toBe('token_sender_mismatch');
});

test('G. staff reviews and releases quarantined email', async ({ page }) => {
    await loginAsStaff(page);
    await page.goto('/soporte/cuarentena');
    await expect(page.getByText('Quarantine test')).toBeVisible();
    await page.getByRole('button', { name: 'Liberar' }).click();
    await expect(page.getByText('Quarantine test')).not.toBeVisible();
});

/* -------------------------------------------------------------------------- */
/* FLOW H — SLA                                                               */
/* -------------------------------------------------------------------------- */

test('H. SLA breach is recorded once and escalation emitted', async ({ page, request }) => {
    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('SLA test');
    await page.getByLabel('Mensaje').fill('Urgent');
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();

    await loginAsStaff(page);
    await page.goto('/soporte');
    
    const advanceResp = await request.post('/api/support/sla/advance-time', {
        data: { minutes: 120 },
    });
    expect(advanceResp.status()).toBe(200);

    const scanResp = await request.post('/api/support/sla/scan', { data: {} });
    expect(scanResp.status()).toBe(200);

    const breachResp = await request.get('/api/support/sla-breaches');
    const bBody = await breachResp.json();
    expect(bBody.breaches).toBe(1);

    const scanResp2 = await request.post('/api/support/sla/scan', { data: {} });
    expect(scanResp2.status()).toBe(200);

    const breachResp2 = await request.get('/api/support/sla-breaches');
    const bBody2 = await breachResp2.json();
    expect(bBody2.breaches).toBe(1);
});

/* -------------------------------------------------------------------------- */
/* FLOW I — AUTOMATION                                                        */
/* -------------------------------------------------------------------------- */

test('I. automation rule triggers on support.sla.breach', async ({ page, request }) => {
    await loginAsStaff(page);
    await page.goto('/automatizaciones');
    await page.getByRole('button', { name: 'Nueva regla' }).click();
    await page.getByLabel('Nombre').fill('SLA Breach Test');
    await page.getByLabel('Evento').selectOption('support.sla.breach');
    await page.getByLabel('Acción').selectOption('create_task');
    await page.getByRole('button', { name: 'Crear regla' }).click();

    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('Auto trigger');
    await page.getByLabel('Mensaje').fill('Will trigger automation');
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();

    await loginAsStaff(page);
    await page.goto('/soporte');
    
    const advanceResp = await request.post('/api/support/sla/advance-time', {
        data: { minutes: 120 },
    });
    expect(advanceResp.status()).toBe(200);

    await request.post('/api/support/sla/scan', { data: {} });
    await page.waitForTimeout(2000);

    const runResp = await request.get('/api/automation/runs');
    const rBody = await runResp.json();
    expect(rBody.runs.length).toBeGreaterThanOrEqual(1);
});

test('I. automation partial retry works', async ({ page, request }) => {
    await loginAsStaff(page);
    await page.goto('/automatizaciones');
    await page.getByRole('button', { name: 'Nueva regla' }).click();
    await page.getByLabel('Nombre').fill('Retry Test');
    await page.getByLabel('Evento').selectOption('support.conversation.created');
    await page.getByLabel('Acción').selectOption('create_task');
    await page.getByRole('button', { name: 'Crear regla' }).click();

    await loginAsClientA(page);
    await page.goto('/portal/soporte');
    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await page.getByLabel('Asunto').fill('Retry conversation');
    await page.getByLabel('Mensaje').fill('Test');
    await page.getByRole('button', { name: 'Enviar mensaje' }).click();

    await page.waitForTimeout(2000);

    const runResp = await request.get('/api/automation/runs');
    const rBody = await runResp.json();
    const failedRun = rBody.runs.find((r: any) => r.status === 'failed');
    
    if (failedRun) {
        await request.post(`/api/automation/runs/${failedRun.id}/retry`, { data: {} });
        await page.waitForTimeout(1000);
        
        const retryResp = await request.get(`/api/automation/runs/${failedRun.id}`);
        const retryBody = await retryResp.json();
        expect(retryBody.status).toBe('completed');
    }
});