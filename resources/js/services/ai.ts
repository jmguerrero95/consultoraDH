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
    primary: { configured: boolean; model: string | null; base_url: string | null };
    fallback: { configured: boolean; model: string | null; base_url: string | null };
    timeout_seconds: number;
    proposal_ttl_minutes: number;
}

export const ai = {
    status(): Promise<AiStatusPayload> {
        return request<AiStatusPayload>('GET', '/api/ai/status');
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
} as const;
