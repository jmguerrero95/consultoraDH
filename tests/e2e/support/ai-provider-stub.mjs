/**
 * A deterministic stand-in for the AI provider, for the end to end suite only.
 *
 * ## Why this exists
 *
 * The journey it serves is: attach a spreadsheet, ask about a value that exists
 * only in that file, get an answer built from the file. The last step needs a
 * model, and the suite was reaching a real one — OpenRouter's free tier — which
 * makes the result depend on somebody else's quota and on the upstream being
 * up. When the free daily allowance ran out, every question answered 500 and
 * the journey failed for a reason that had nothing to do with attachments.
 *
 * A suite that can fail because a shared free tier ran out is not measuring
 * this pipeline. So the provider here is local, deterministic and free.
 *
 * ## What is still real
 *
 * Only the HTTP hop to the model is replaced. Everything that the journey is
 * actually about remains the product's own: the file is uploaded through the
 * visible picker, queued, ingested by the real `DocumentIngestor` on a real
 * Redis worker, chunked and indexed by the real code, retrieved by the real
 * `DocumentRetriever` for that user and that conversation, and the passages it
 * retrieves are what arrives here in the request body.
 *
 * ## How the answer is built, and why this is not a rubber stamp
 *
 * The stub answers *from the request it receives*. It looks for the distinctive
 * values that exist in the fixture spreadsheet and nowhere else, and quotes back
 * only the ones it actually found in the messages.
 *
 * That is the whole point. If retrieval silently returned nothing, or returned
 * another conversation's file, or the attachment had gone unscoped, the passages
 * would not carry the value and the stub would have no marker to quote — it says
 * so plainly instead, and the assertion fails. The test cannot pass because the
 * provider is agreeable; it can only pass because the document's content really
 * reached the model.
 *
 * ## Scope
 *
 * Wired in by `compose.e2e.yaml` for `app-e2e` alone. The development and
 * production environments reach their real provider and are untouched by this
 * file, which exists only in the end to end stack.
 */
import { createServer } from 'node:http';

const PORT = Number(process.env.AI_STUB_PORT ?? 8100);

/**
 * Values that exist only inside `marcadores-e2e.xlsx`.
 *
 * `VALOR-ORIGEN-*` is the figure the journey asks for; `MARCADOR-E2E-*` is a
 * second marker used by the ownership journeys. Neither string occurs anywhere
 * in the application, so finding one here can only have come from the document.
 */
const MARKER = /(?:VALOR-ORIGEN|MARCADOR-E2E)-[A-Z0-9-]+/g;

function readBody(request) {
    return new Promise((resolve, reject) => {
        const chunks = [];

        request.on('data', (chunk) => chunks.push(chunk));
        request.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
        request.on('error', reject);
    });
}

function markersIn(payload) {
    const found = new Set();

    for (const message of payload.messages ?? []) {
        const content = typeof message?.content === 'string' ? message.content : '';
        for (const value of content.match(MARKER) ?? []) {
            found.add(value);
        }
    }

    return [...found];
}

function completion(content) {
    return {
        id: 'chatcmpl-e2e-deterministic',
        object: 'chat.completion',
        created: 0,
        model: payloadModel,
        choices: [
            {
                index: 0,
                message: { role: 'assistant', content },
                finish_reason: 'stop',
            },
        ],
        usage: { prompt_tokens: 0, completion_tokens: 0, total_tokens: 0 },
    };
}

// Set per request; the single-threaded loop makes that safe.
let payloadModel = 'e2e-deterministic';

const server = createServer((request, response) => {
    const send = (status, body) => {
        response.writeHead(status, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify(body));
    };

    // The assistant's own liveness probe.
    if (request.method === 'GET' && request.url === '/health') {
        return send(200, { status: 'ok' });
    }

    if (request.method !== 'POST' || !request.url?.startsWith('/v1/chat/completions')) {
        return send(404, { error: { message: 'not found' } });
    }

    readBody(request)
        .then((raw) => {
            let payload;

            try {
                payload = JSON.parse(raw);
            } catch {
                return send(400, { error: { message: 'the request body was not JSON' } });
            }

            payloadModel = typeof payload.model === 'string' ? payload.model : 'e2e-deterministic';

            const markers = markersIn(payload);

            // No tool calls: a deterministic answer is the whole point, and a
            // stub that invented a tool call would be simulating the assistant
            // rather than the transport.
            const content = markers.length
                ? `Según el documento adjunto: ${markers.join(', ')}.`
                : 'No encontré ese dato en los documentos adjuntos a esta conversación.';

            console.log(`[ai-stub] ${markers.length} marker(s) in context: ${markers.join(', ') || 'none'}`);

            return send(200, completion(content));
        })
        .catch((error) => send(500, { error: { message: String(error) } }));
});

server.listen(PORT, '0.0.0.0', () => {
    console.log(`[ai-stub] deterministic provider listening on ${PORT}`);
});