import { request } from '@/services/http';

/**
 * The AI assistant's HTTP surface.
 *
 * A separate module for the same reason `a05.ts` is separate from `api.ts`: the
 * assistant is a distinct feature with its own conversation, attachment, task
 * and suggestion vocabulary, and folding all of it into the shared client would
 * bury the business endpoints.
 *
 * `request<T>` resolves with the decoded body, so every type below is the exact
 * JSON the controller returns — not an envelope. That distinction matters: the
 * assistant endpoints answer with `{ reply, ... }` directly, and declaring them
 * as `{ data: ... }` is what made the previous page render an empty transcript
 * with no error to explain it.
 */

/** A conversation as the list endpoint reports it. */
export interface AiConversationSummary {
    id: number;
    title: string | null;
    created_at: string | null;
}

export type AiMessageRole = 'user' | 'assistant' | 'tool';

export interface AiMessagePayload {
    role: AiMessageRole;
    content: string;
    provider: string | null;
    model: string | null;
    created_at: string | null;
}

/**
 * A write the assistant would like to make. It has changed nothing: the
 * arguments are stored and nothing runs until a person confirms.
 */
export interface AiProposal {
    id: number;
    tool: string;
    arguments: Record<string, unknown>;
    expires_at: string;
    pending: true;
}

/** What a read tool returned during the turn. */
export interface AiToolResult {
    tool: string;
    output: unknown;
}

export interface AiTurnPayload {
    conversation_id: number;
    reply: string;
    provider: string | null;
    model: string | null;
    proposals: AiProposal[];
    /** Present on `ask`, absent on `messages` — both call the same service. */
    tools?: AiToolResult[];
}

export interface AiStatusPayload {
    ai_enabled: boolean;
    /**
     * Which provider is answering right now (BUG-017).
     *
     * `none` means nothing is configured; `available` means the primary is up;
     * `fallback` means the primary is cooling down after a failure and the
     * fallback is covering; `unavailable` means every provider is cooling down.
     * The value changes with reality, unlike the old `configured` booleans, which
     * were true for a provider that had been failing for an hour.
     */
    state: 'none' | 'available' | 'fallback' | 'unavailable';
    /** Whether a question would be answered at all. */
    available: boolean;
    /** The model that would answer now, or null. */
    active_model: string | null;
    /** Seconds each slot is still cooling down for, keyed by slot name. */
    cooldowns: Record<string, number>;
    primary: { configured: boolean; model: string | null };
    fallback: { configured: boolean; model: string | null };
    timeout_seconds: number;
    proposal_ttl_minutes: number;
}

/**
 * One proactive suggestion, already written out.
 *
 * `explanation` is generated server-side from the stored evidence rather than
 * assembled in the browser, so the sentence and the numbers behind it cannot
 * drift apart. `evidence` travels with it so the UI can show what it is
 * reasoning from instead of asking anyone to trust a claim.
 */
export interface AiSuggestionPayload {
    id: number;
    explanation: string;
    suggested_action: string;
    category: string;
    module: string;
    operation: string;
    evidence: Record<string, unknown>;
    relevance: number;
    expires_at: string;
}

/**
 * The envelope the suggestion endpoint answers with.
 *
 * `suggestion` is null on almost every call and that is the feature working,
 * not an error - see `SuggestionController`.
 */
export interface AiSuggestionEnvelope {
    suggestion: AiSuggestionPayload | null;
}

export interface AiTaskPayload {
    id: number;
    kind: string;
    status: string;
    progress: number;
    stage: string | null;
    name: string | null;
    result: Record<string, unknown> | null;
    error: string | null;
    attempts: number;
    finished: boolean;
    recoverable: boolean;
    conversation_id: number | null;
    created_at: string | null;
    finished_at: string | null;
}

export const ai = {
    status(): Promise<AiStatusPayload> {
        return request<AiStatusPayload>('GET', '/api/ai/status');
    },

    /**
     * The one suggestion worth showing, if there is one.
     *
     * `module` and `operation` say where the person is, so a signal can be
     * relevant to the screen they are actually looking at.
     */
    suggestion(module?: string, operation?: string, clientId?: number | null): Promise<AiSuggestionEnvelope> {
        const query = new URLSearchParams();
        if (module) query.set('module', module);
        if (operation) query.set('operation', operation);
        // Which client the screen is about. A single client's statement has to
        // say so, or the server has no way to tell whose debt it is reporting.
        if (typeof clientId === 'number') query.set('client_id', String(clientId));

        const suffix = query.toString() ? `?${query.toString()}` : '';
        return request<AiSuggestionEnvelope>('GET', `/api/ai/suggestion${suffix}`);
    },

    /** "Yes, tell me about that." Records the answer; changes nothing else. */
    acceptSuggestion(id: number): Promise<{ accepted: boolean; suggestion_id: number }> {
        return request<{ accepted: boolean; suggestion_id: number }>('POST', `/api/ai/suggestions/${id}/accept`);
    },

    /** "Not this one." Remembered, so it is not raised again. */
    dismissSuggestion(id: number): Promise<{ dismissed: boolean; suggestion_id: number }> {
        return request<{ dismissed: boolean; suggestion_id: number }>('POST', `/api/ai/suggestions/${id}/dismiss`);
    },

    conversations(): Promise<{ conversations: AiConversationSummary[] }> {
        return request<{ conversations: AiConversationSummary[] }>('GET', '/api/ai/conversations');
    },

    /**
     * Ask a question, optionally inside an existing conversation.
     *
     * Omitting `conversation_id` creates a new one and returns its id.
     */
    ask(question: string, conversationId?: number | null): Promise<AiTurnPayload> {
        return request<AiTurnPayload>('POST', '/api/ai/conversations', {
            body: {
                question,
                ...(conversationId === undefined || conversationId === null ? {} : { conversation_id: conversationId }),
            },
        });
    },

    /**
     * Open an empty conversation.
     *
     * So a document can be attached *before* the first question. Waiting until
     * something has been asked means the upload lands with no conversation to
     * belong to - and a question asked afterwards, in whatever thread it lands
     * in, cannot reach it.
     */
    openConversation(): Promise<{ conversation_id: number; title: string | null; created_at: string | null }> {
        return request<{ conversation_id: number; title: string | null; created_at: string | null }>('POST', '/api/ai/conversations/open');
    },

    history(conversationId: number): Promise<{ conversation_id: number; messages: AiMessagePayload[] }> {
        return request<{ conversation_id: number; messages: AiMessagePayload[] }>('GET', `/api/ai/conversations/${conversationId}`);
    },

    confirmProposal(proposalId: number): Promise<{ confirmed: boolean; proposal_id: number; tool: string; result: unknown }> {
        return request<{ confirmed: boolean; proposal_id: number; tool: string; result: unknown }>(
            'POST',
            `/api/ai/action-proposals/${proposalId}/confirm`,
        );
    },

    rejectProposal(proposalId: number): Promise<{ rejected: boolean; proposal_id: number; tool: string }> {
        return request<{ rejected: boolean; proposal_id: number; tool: string }>('POST', `/api/ai/action-proposals/${proposalId}/reject`);
    },
    /**
     * Background work this person has asked for.
     *
     * Also the recovery path: the server holds the result, so this answers
     * correctly after a reload, a lock screen, or a reconnect.
     */
    tasks(): Promise<{ tasks: AiTaskPayload[] }> {
        return request<{ tasks: AiTaskPayload[] }>('GET', '/api/ai/tasks');
    },

    /**
     * Queue an upload.
     *
     * 202, not 200: the file is stored and the work is queued, and answering
     * "created" would claim a result that does not exist yet.
     */
    uploadTask(file: File, conversationId?: number | null): Promise<{ task: AiTaskPayload }> {
        const form = new FormData();
        form.append('file', file);

        if (conversationId !== undefined && conversationId !== null) {
            form.append('conversation_id', String(conversationId));
        }

        return request<{ task: AiTaskPayload }>('POST', '/api/ai/tasks', { body: form });
    },

    cancelTask(id: number): Promise<{ task: AiTaskPayload }> {
        return request<{ task: AiTaskPayload }>('POST', `/api/ai/tasks/${id}/cancel`);
    },

    /** Queue a research run. 202: queued, not answered. */
    researchTask(question: string, conversationId?: number | null): Promise<{ task: AiTaskPayload }> {
        return request<{ task: AiTaskPayload }>('POST', '/api/ai/tasks/research', {
            body: {
                question,
                ...(conversationId === undefined || conversationId === null ? {} : { conversation_id: conversationId }),
            },
        });
    },

    /**
     * Queue a document generation.
     *
     * Either `content` (the body, ready to write) or `brief` (what it should
     * say) - the server refuses a request with neither, rather than producing an
     * empty file.
     */
    generateTask(
        options: { format: string; filename: string; content?: string; brief?: string },
        conversationId?: number | null,
    ): Promise<{ task: AiTaskPayload }> {
        return request<{ task: AiTaskPayload }>('POST', '/api/ai/tasks/document-generate', {
            body: {
                ...options,
                ...(conversationId === undefined || conversationId === null ? {} : { conversation_id: conversationId }),
            },
        });
    },

} as const;
