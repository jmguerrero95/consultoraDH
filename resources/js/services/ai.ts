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
    /** Last activity, used to order the list. Optional for older servers. */
    updated_at?: string | null;
}

export type AiMessageRole = 'user' | 'assistant' | 'tool';

export interface AiMessagePayload {
    /** Present on the history endpoint; the id a generated file points at. */
    id?: number;
    role: AiMessageRole;
    content: string;
    provider: string | null;
    model: string | null;
    created_at: string | null;
    /**
     * Files this reply produced, filled in by the interface from the history
     * endpoint's flat artifact list. Not sent by the server on the message.
     */
    artifacts?: AiArtifact[];
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
    /**
     * The message this proposal belongs beside, so it can be rendered inline
     * rather than piled in a separate panel. `null` for proposals created
     * before this migration.
     */
    message_id?: number | null;
}

/** What a read tool returned during the turn. */
export interface AiToolResult {
    tool: string;
    output: unknown;
}

/**
 * A file the assistant produced and that belongs to a conversation.
 *
 * The file itself is private: `download_url` is an authorised endpoint, not a
 * public path, and it only answers the person whose id it was stored under.
 * `size_bytes` and `format` are what the card shows before anyone downloads.
 */
export interface AiArtifact {
    id: number;
    filename: string;
    format: string;
    size_bytes: number;
    download_url: string;
    expires_at: string | null;
    /**
     * The reply this file was generated in, so it can be rendered beside that
     * answer rather than piled at the end of the transcript. `null` for a file
     * with no reply to sit next to (a background task, or a proposal-only turn).
     */
    message_id?: number | null;
}

/**
 * Pair generated files with the reply they were generated in.
 *
 * One place, used by the widget and by the full page, so the two cannot drift:
 * both render a file beside the message whose id it names. Files with no message
 * — a background task, or a turn that produced no prose — come back separately as
 * `orphan`, for the collapsed secondary section.
 */
export function groupArtifactsByMessage(artifacts: AiArtifact[]): {
    byMessage: Map<number, AiArtifact[]>;
    orphan: AiArtifact[];
} {
    const byMessage = new Map<number, AiArtifact[]>();
    const orphan: AiArtifact[] = [];

    for (const artifact of artifacts) {
        if (typeof artifact.message_id === 'number') {
            const list = byMessage.get(artifact.message_id) ?? [];
            list.push(artifact);
            byMessage.set(artifact.message_id, list);
        } else {
            orphan.push(artifact);
        }
    }

    return { byMessage, orphan };
}

/**
 * Pair proposals with the message that asked for them.
 *
 * Shared by the widget and the full page so proposals render inline with the
 * user question that triggered them. Proposals with no message_id — created
 * before this migration — are returned as `orphan` for a collapsed secondary
 * section.
 */
export function groupProposalsByMessage(proposals: AiProposal[]): {
    byMessage: Map<number, AiProposal[]>;
    orphan: AiProposal[];
} {
    const byMessage = new Map<number, AiProposal[]>();
    const orphan: AiProposal[] = [];

    for (const proposal of proposals) {
        if (typeof proposal.message_id === 'number') {
            const list = byMessage.get(proposal.message_id) ?? [];
            list.push(proposal);
            byMessage.set(proposal.message_id, list);
        } else {
            orphan.push(proposal);
        }
    }

    return { byMessage, orphan };
}

/**
 * A file attached to a conversation, as the server reports it.
 *
 * Present so that reloading a thread rebuilds the attachment list from the
 * server rather than from memory: a file uploaded in the widget must still be
 * there when the same thread is opened in the full page, or after the phone
 * wakes up. `status` is the ingest state (`processed`, `unreadable`, `empty`),
 * not a task status.
 */
export interface AiAttachmentSummary {
    id: number;
    conversation_id: number | null;
    name: string;
    mime_type: string | null;
    extension: string | null;
    size_bytes: number | null;
    status: string;
    status_note: string | null;
    created_at: string | null;
}

export interface AiTurnPayload {    conversation_id: number;
    reply: string;
    provider: string | null;
    model: string | null;
    proposals: AiProposal[];
    /** Present on `ask`, absent on `messages` — both call the same service. */
    tools?: AiToolResult[];
    /**
     * Files produced during this turn — a PDF, an XLSX, a Word document.
     *
     * Here rather than in a separate panel because the file is the answer: a
     * turn that ends with "te propongo generar el documento" and no download is
     * exactly the defect this carries the fix for.
     */
    artifacts?: AiArtifact[];
}

/**
 * The health of one model in the pool (BUG-020-R2).
 *
 * Safe metadata only: which model, which host, whether it answered recently and
 * how fast. It carries no prompt, no document and no credential.
 */
export interface AiModelHealth {
    model: string;
    provider: string;
    priority: number;
    tools: boolean;
    /** `ok`, `degraded`, `unchecked` or `quota_limited`. */
    state: string;
    cooldown_seconds: number;
    fails: number;
    last_kind: string | null;
    last_ok_seconds_ago: number | null;
    last_latency_ms: number | null;
}

/**
 * Availability of one kind of operation.
 *
 * The single `state` on the status cannot answer "can it read my spreadsheet?":
 * that needs a tool-capable provider *and* one authorised for private data. This
 * is that answer, per operation, derived on the server from the real pool.
 */
export interface AiCapabilityAvailability {
    label: string;
    /**
     * `available` (a proven provider can answer, with a backup),
     * `degraded` (works, but with a single independent provider),
     * `unverified` (configured, nothing has answered recently),
     * `quota_limited`, `unavailable`, `blocked` or `unconfigured`.
     */
    state: 'available' | 'degraded' | 'unverified' | 'quota_limited' | 'unavailable' | 'blocked' | 'unconfigured';
    available: boolean;
    eligible: number;
    ready: number;
    independent_providers: number;
    preferred_model: string | null;
    last_success: string | null;
    retry_after_seconds: number | null;
    reason: string | null;
}

/** One provider attempt, kept so a failure can be explained rather than guessed. */
export interface AiDiagnostic {
    at: number;
    task: string | null;
    model: string;
    provider: string;
    outcome: 'ok' | 'failed';
    kind: string | null;
    http: number | null;
    scope: string | null;
    detail: string | null;
    latency_ms: number;
}

export interface AiStatusPayload {
    ai_enabled: boolean;
    /**
     * What the pool can do right now (BUG-017, widened by BUG-020-R2).
     *
     * `none` means nothing is configured; `available` means a model that recently
     * answered is usable; `fallback` means the first choice is down and a backup
     * is carrying the turn; `unchecked` means nothing has been tried yet this
     * process; `quota_limited` means the provider is refusing on quota, which no
     * amount of model-switching within the same account fixes; `unavailable`
     * means every candidate is cooling down. The value changes with reality,
     * unlike the old `configured` booleans, which stayed true for a provider that
     * had been failing for an hour.
     */
    state: 'none' | 'available' | 'fallback' | 'unchecked' | 'quota_limited' | 'unavailable';
    /** Whether a question would be answered at all. */
    available: boolean;
    /** The model that would answer now, or null. */
    active_model: string | null;
    /** Which model actually answered last, if any. */
    last_result?: { model: string; provider: string; at: number } | null;
    /** Seconds each slot is still cooling down for, keyed by slot name. */
    cooldowns: Record<string, number>;
    /** Every candidate, in the order the resolver would try it. */
    models?: AiModelHealth[];
    /** Availability per kind of operation, so the UI stops over-promising. */
    capabilities?: Record<string, AiCapabilityAvailability>;
    /** How many independent providers can serve private tool work right now. */
    independent_providers?: number;
    /** The most recent attempts, newest first. */
    diagnostics?: AiDiagnostic[];
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
     * Rename a conversation the caller owns.
     *
     * Presentation only: the server changes the title and nothing else.
     */
    renameConversation(conversationId: number, title: string): Promise<{ conversation_id: number; title: string }> {
        return request<{ conversation_id: number; title: string }>('PATCH', `/api/ai/conversations/${conversationId}`, {
            body: { title },
        });
    },

    /**
     * Delete a conversation the caller owns.
     *
     * The server removes the thread and the files it generated; business
     * records a confirmed proposal created are untouched.
     */
    deleteConversation(conversationId: number): Promise<{ deleted: boolean; conversation_id: number }> {
        return request<{ deleted: boolean; conversation_id: number }>('DELETE', `/api/ai/conversations/${conversationId}`);
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

    history(conversationId: number): Promise<{ conversation_id: number; messages: AiMessagePayload[]; artifacts?: AiArtifact[]; attachments?: AiAttachmentSummary[]; proposals?: AiProposal[] }> {
        return request<{ conversation_id: number; messages: AiMessagePayload[]; artifacts?: AiArtifact[]; attachments?: AiAttachmentSummary[]; proposals?: AiProposal[] }>(
            'GET',
            `/api/ai/conversations/${conversationId}`,
        );
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

    /** One task, for polling its progress after a reload or a reconnect. */
    task(id: number): Promise<{ task: AiTaskPayload }> {
        return request<{ task: AiTaskPayload }>('GET', `/api/ai/tasks/${id}`);
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
