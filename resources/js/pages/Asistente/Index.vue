<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import SafeMarkdown from '@/components/ui/SafeMarkdown.vue';
import ArtifactCard from '@/components/assistant/ArtifactCard.vue';
import AttachmentPicker from '@/components/assistant/AttachmentPicker.vue';
import MicButton from '@/components/assistant/MicButton.vue';
import SpeakButton from '@/components/assistant/SpeakButton.vue';
import SuggestionHint from '@/components/assistant/SuggestionHint.vue';
import TaskPanel from '@/components/assistant/TaskPanel.vue';
import { useSuggestion } from '@/composables/useSuggestion';
import { useAiTasks } from '@/composables/useTasks';
import { useSuggestionContext } from '@/composables/useSuggestionContext';
import { useVoice } from '@/composables/useVoice';
import { useRoute } from 'vue-router';
import { ai, groupArtifactsByMessage, groupProposalsByMessage, newClientTurnId } from '@/services/ai';
import { useAuthStore } from '@/stores/auth';
import { readDraft, writeDraft } from '@/utils/drafts';

import type { AiArtifact, AiAttachmentSummary, AiConversationSummary, AiMessagePayload, AiProposal, AiStatusPayload } from '@/services/ai';
import type { AiTaskPayload } from '@/composables/useTasks';

/**
 * The assistant screen.
 *
 * Two contracts this page depends on, both learned the hard way:
 *
 * - `request<T>` resolves with the decoded body, so a turn is `{ reply, ... }`
 *   and not `{ data: { reply } }`. Reading `.data` here is what left the
 *   transcript empty with nothing logged.
 * - The controller answers errors as `{ error: '...' }`. The shared error
 *   object carries that text, so it is surfaced verbatim rather than replaced
 *   with a generic apology that hides the actual cause.
 */

const conversations = ref<AiConversationSummary[]>([]);
const selectedId = ref<number | null>(null);
const messages = ref<AiMessagePayload[]>([]);
/** Proposals with no message to sit beside (created before this migration). */
const orphanProposals = ref<AiProposal[]>([]);
/** Files this conversation has produced, shown in the thread that asked for them. */
const artifacts = ref<AiArtifact[]>([]);
/** Files the person attached to this thread, rebuilt from the server on reload. */
const attachments = ref<AiAttachmentSummary[]>([]);
/** Whether the conversation drawer is open. Meaningful on mobile only. */
const drawerOpen = ref(false);
const sources = ref<{ title: string; url: string; domain: string }[]>([]);
const draft = ref('');
const sending = ref(false);
const loading = ref(true);
const status = ref<AiStatusPayload | null>(null);
const error = ref<string | null>(null);
/** The server's own account of a confirmed write, shown in green. */
const confirmation = ref<string | null>(null);

/** The conversation being renamed inline, and the title being typed. */
const editingId = ref<number | null>(null);
const editTitle = ref('');

/** The transcript element, so a new turn can be brought into view. */
const transcriptEl = ref<HTMLElement | null>(null);

/**
 * Whether the person has deliberately scrolled up.
 *
 * While they read an earlier answer, a new message must not drag them back to
 * the bottom. The flag is reset the moment they return to the latest turn.
 */
const pinnedUp = ref(false);

const auth = useAuthStore();

// Every keystroke is kept for this person and this thread, so an F5 — or an
// accidental navigation — does not throw away what was typed. Scoped to the
// user id and the conversation id, so nothing crosses accounts or threads.
watch(draft, (value) => {
    writeDraft(currentUserId(), selectedId.value, value);
});

// --- Background work -------------------------------------------------------
//
// One set of task states for the whole screen. The picker, the research and
// generate controls and the panel all report into `tasks`, so a file uploaded
// here is the same row the panel lists and the same one a push notification is
// about.
const tasks = useAiTasks();

const uploading = ref(false);

const uploadError = ref<string | null>(null);

const longRunningError = ref<string | null>(null);

const briefOpen = ref(false);

const briefFormat = ref('pdf');

const briefFilename = ref('documento');

const briefText = ref('');

/**
 * The background work that belongs to the thread on screen.
 *
 * A task remembers the conversation it was started from, so research asked for
 * here reports here, next to the question, rather than only in the panel at the
 * top of the page. Work with no conversation is shown too: it is this person's
 * work and it is still running.
 */
const inlineTasks = computed(() =>
    tasks.pending().filter((task) => (task.conversation_id ?? null) === (selectedId.value ?? null)),
);

const TASK_KIND_LABELS: Record<string, string> = {
    research: 'Investigando',
    document_generate: 'Generando documento',
    document_ingest: 'Procesando archivo',
};

/** A one-line, human description of what a task is doing right now. */
function taskLabel(task: AiTaskPayload): string {
    const base = task.stage && task.stage.trim() !== '' ? task.stage : (TASK_KIND_LABELS[task.kind] ?? 'Procesando');
    return task.name ? `${base}: ${task.name}` : base;
}

/**
 * Upload a document and queue its indexing.
 *
 * Sent without a conversation when none is selected yet: the work is queued and
 * persistent, so it does not need a thread to exist first, and refusing the
 * upload until somebody had asked a question would be an arbitrary rule. The
 * attachment can be read from any conversation afterwards.
 */
async function attachFile(file: File): Promise<void> {
    uploading.value = true;
    uploadError.value = null;

    try {
        // A conversation is opened first if there is none, so the attachment
        // belongs to a thread from the start. Uploading without one produced a
        // document no conversation could reach, which is the difference between
        // "the assistant read my file" and "it answered from its own memory".
        let conversationId = selectedId.value;

        if (conversationId === null) {
            const opened = await ai.openConversation();
            conversationId = opened.conversation_id;
            selectedId.value = conversationId;
        }

        await ai.uploadTask(file, conversationId);
        // The panel owns the list; refreshing it here is what makes the new row
        // appear without waiting for the next poll.
        await tasks.refresh();
    } catch (e) {
        // Said plainly and shown. A silent failure here is the worst outcome:
        // the person chose a file, watched nothing happen, and has no idea
        // whether it arrived.
        uploadError.value = readError(e, 'No fue posible adjuntar el archivo.');
    } finally {
        uploading.value = false;
    }
}

async function startResearch(): Promise<void> {
    const question = draft.value.trim();

    if (question === '') {
        longRunningError.value = 'Escribe la pregunta que quieres investigar.';

        return;
    }

    longRunningError.value = null;

    try {
        await ai.researchTask(question, selectedId.value);
        draft.value = '';
        await tasks.refresh();
    } catch (e) {
        longRunningError.value = readError(e, 'No fue posible iniciar la investigación.');
    }
}

async function startGeneration(): Promise<void> {
    longRunningError.value = null;

    try {
        await ai.generateTask(
            { format: briefFormat.value, filename: briefFilename.value, brief: briefText.value },
            selectedId.value,
        );
        briefOpen.value = false;
        briefText.value = '';
        await tasks.refresh();
    } catch (e) {
        longRunningError.value = readError(e, 'No fue posible iniciar la generación.');
    }
}

// Where an acceptance from another screen arrives.
//
// The suggested action is put in the composer and nothing more. Accepting a
// suggestion is a decision to *talk about* something; sending is a separate,
// deliberate act, and no administrative write happens on the way here.
const route = useRoute();

function applySuggestedAction(value: unknown): void {
    if (typeof value !== 'string' || value.trim() === '') {
        return;
    }

    draft.value = value.trim();
    focusComposer();
}

/**
 * Move the caret to the composer, so the prepared action is ready to be read,
 * corrected and sent rather than fired off unseen.
 */
function focusComposer(): void {
    if (typeof document === 'undefined') {
        return;
    }

    requestAnimationFrame(() => {
        document.getElementById('cdh-ai-input')?.focus();
    });
}

// Read on arrival and on any change, so a link that swaps the query while the
// screen is open still lands.
//
// `route` is optional: this page is mounted in tests without a router, and
// `useRoute()` returns nothing there. Reading `route.query` unguarded threw a
// TypeError before anything was rendered, which took every assertion on the
// screen down with it.
watch(() => route?.query?.suggested, applySuggestedAction, { immediate: true });

/**
 * The conversation an incoming link asked to open, or null.
 *
 * The widget's "open full page" link carries the active thread id, so arriving
 * here selects that same thread instead of starting an unrelated one.
 */
function conversationFromQuery(): number | null {
    const raw = route?.query?.conversation;
    const value = Array.isArray(raw) ? raw[0] : raw;

    if (typeof value !== 'string' || value.trim() === '') {
        return null;
    }

    const id = Number(value);

    return Number.isInteger(id) && id > 0 ? id : null;
}

// A link that swaps to another conversation while this screen is already open
// must land too, not only the first arrival.
watch(() => route?.query?.conversation, () => {
    const id = conversationFromQuery();

    if (id !== null && id !== selectedId.value) {
        void selectConversation(id);
    }
});

// Voice is optional everywhere: neither control appears where the browser
// cannot provide it, and the composer works exactly the same without them.
const { dictation, playback } = useVoice();

// The proactive side. Fetched once when the screen opens rather than on a
// timer: the cost and the interruption both belong to a person who is here.
const hint = useSuggestion();

// The assistant screen spans every module, so it has no single context of its
// own and therefore raises nothing by itself. It waits to be told what the
// person is actually doing, which the surrounding screens decide.
const { context: suggestionContext } = useSuggestionContext();

const enabled = computed(() => status.value?.ai_enabled === true);
const model = computed(() => status.value?.active_model ?? '—');

/**
 * What the assistant can do right now, per kind of operation.
 *
 * The single `state` cannot answer "can it read my spreadsheet?", which needs a
 * tool-capable provider *and* one authorised for private data. When the server
 * sends the per-operation report, this is the sentence the header shows.
 */
const capabilityRows = computed(() => Object.values(status.value?.capabilities ?? {}));

/** Operations the person should be warned about, if any. */
const limitedOperations = computed(() =>
    capabilityRows.value.filter((row) => !row.available),
);

/** An unconfigured provider is reported plainly rather than as a failure. */
const availability = computed(() => {
    if (!status.value) {
        return 'Comprobando el servicio…';
    }

    if (!status.value.ai_enabled) {
        return 'Asistente desactivado en este entorno.';
    }

    if (status.value.state === 'none') {
        return 'No hay ningún proveedor de IA configurado.';
    }

    // When the server reports availability per operation, that is more truthful
    // than the pool-wide state: it says whether the thing the person is about to
    // ask can actually be done.
    if (capabilityRows.value.length > 0) {
        const privateReady = status.value.capabilities?.internal_data;

        if (privateReady && !privateReady.available) {
            return privateReady.reason
                ?? 'La consulta de datos internos está limitada en este momento.';
        }

        const degraded = limitedOperations.value.length > 0;

        if (status.value.state === 'quota_limited' || status.value.state === 'unavailable') {
            return status.value.state === 'quota_limited'
                ? 'El servicio de inteligencia artificial alcanzó temporalmente su límite de uso. Puedes reintentar cuando vuelva a estar disponible.'
                : 'No hay un proveedor de inteligencia artificial disponible en este momento; se reintentará automáticamente.';
        }

        if (status.value.state === 'unchecked') {
            return degraded
                ? 'Servicio disponible para conversación; algunas operaciones están limitadas temporalmente.'
                : 'Servicio configurado; comprobando su disponibilidad con la primera consulta.';
        }

        const providers = status.value.independent_providers ?? 0;
        const backup = providers > 1 ? ' con respaldo automático' : '';

        return `Disponible${model.value !== '—' ? ` con ${model.value}` : ''}${backup}.`;
    }

    // Fallback for a server that does not yet send the capability report.
    switch (status.value.state) {
        case 'unavailable':
            return 'No hay un proveedor de inteligencia artificial disponible en este momento; se reintentará automáticamente.';
        case 'quota_limited':
            return 'El servicio de inteligencia artificial alcanzó temporalmente su límite de uso. Puedes reintentar cuando vuelva a estar disponible.';
        case 'fallback':
            return `Operativo con el proveedor de respaldo${model.value !== '—' ? ` (${model.value})` : ''}.`;
        case 'available':
            // Only a recent, observed answer is "disponible".
            return `Disponible${model.value !== '—' ? ` con ${model.value}` : ''}.`;
        default:
            // `unchecked`: configured, but with no recent proof it answers, so the
            // screen does not claim it is working.
            return 'Disponibilidad del servicio sin comprobar todavía.';
    }
});

onMounted(async () => {
    await Promise.all([loadStatus(), loadConversations()]);

    // A link from the widget names the conversation to open; otherwise reopen
    // the thread this person was last reading, so an F5 does not lose it.
    const target = conversationFromQuery() ?? storedSelected();

    if (target !== null && target !== selectedId.value) {
        await selectConversation(target);
    } else {
        // No thread named: restore whatever was typed for "new conversation".
        loadDraft();
    }

    loading.value = false;

    // After the page has settled, and independently of it: a suggestion that
    // failed to load must never keep the screen from opening.
    //
    // The assistant page is cross-module, so it asks with whatever context the
    // screen underneath it reports - and with none at all when there is none,
    // in which case nothing is fetched. That is the general shape every
    // integrated screen uses; this one only happens to sit on top of them.
    const context = suggestionContext();

    if (context !== null) {
        await hint.refresh(context.module, context.operation);
    }
});

/**
 * Take up a suggestion.
 *
 * Accepting does not open anything by itself - the server has recorded that the
 * person chose to talk about this. What follows is an ordinary first turn, so
 * the transcript, the proposals and the confirm flow all behave exactly as they
 * would if the person had typed the question themselves.
 *
 * The turn is sent **only** once the server has confirmed. Sending it
 * otherwise is the bug this guards: a rejected acceptance left the UI behaving
 * as though it had been accepted, so the assistant opened a conversation about a
 * problem the server still believed had never been raised.
 */
async function acceptHint(id: number): Promise<void> {
    const context = hint.suggestion.value;

    const confirmed = await hint.accept(id);

    if (!confirmed || context === null) {
        error.value = 'No fue posible registrar la sugerencia. Inténtalo de nuevo.';

        return;
    }

    draft.value = context.suggested_action;
    await send();
}

async function dismissHint(id: number): Promise<void> {
    await hint.dismiss(id);
}

async function loadStatus(): Promise<void> {
    try {
        status.value = await ai.status();
    } catch {
        status.value = null;
    }
}

async function loadConversations(): Promise<void> {
    try {
        const payload = await ai.conversations();
        conversations.value = payload.conversations;
    } catch (e) {
        error.value = readError(e, 'No fue posible cargar las conversaciones.');
    }
}

/** This person's id, for scoping a draft to them. */
function currentUserId(): number | null {
    return auth.user?.id ?? null;
}

/**
 * The thread this person was reading, kept across a reload.
 *
 * Only the id is stored; the messages are always re-read from the server. The
 * key carries the user id, so two accounts on one browser never reopen each
 * other's thread. It is cleared when the thread is deleted or cannot be loaded.
 */
const STORAGE_CONVERSATION = 'cdh.asistente.conversation';

function conversationStorageKey(): string {
    return `${STORAGE_CONVERSATION}.${currentUserId() ?? 'anon'}`;
}

function persistSelected(): void {
    try {
        if (selectedId.value === null) {
            window.localStorage.removeItem(conversationStorageKey());
        } else {
            window.localStorage.setItem(conversationStorageKey(), String(selectedId.value));
        }
    } catch {
        // Storage can be unavailable (private mode, policy). The page still
        // works for this session; it just will not reopen the thread.
    }
}

function storedSelected(): number | null {
    try {
        const raw = window.localStorage.getItem(conversationStorageKey());
        const value = raw === null ? Number.NaN : Number(raw);

        return Number.isInteger(value) && value > 0 ? value : null;
    } catch {
        return null;
    }
}

/**
 * Bring the latest turn into view, unless the person has scrolled up to read.
 *
 * `force` is used when the person themselves acted (sent a message), because
 * then following the newest turn is what they asked for.
 */
async function scrollToLatest(force = false): Promise<void> {
    await nextTick();

    const el = transcriptEl.value;

    if (el === null) {
        return;
    }

    if (force || !pinnedUp.value) {
        el.scrollTop = el.scrollHeight;
        pinnedUp.value = false;
    }
}

/** Remember whether the person is reading earlier in the transcript. */
function onTranscriptScroll(): void {
    const el = transcriptEl.value;

    if (el === null) {
        return;
    }

    pinnedUp.value = el.scrollHeight - el.scrollTop - el.clientHeight > 48;
}

/** Load the draft this person had for the thread now selected. */
function loadDraft(): void {
    draft.value = readDraft(currentUserId(), selectedId.value);
}

/** Persist the composer for the thread now selected, or clear it when empty. */
function persistDraft(): void {
    writeDraft(currentUserId(), selectedId.value, draft.value);
}

/**
 * Begin renaming a conversation in place.
 *
 * Renaming is presentation only: it changes no message and no proposal.
 */
function startRename(conversation: AiConversationSummary): void {
    editingId.value = conversation.id;
    editTitle.value = conversation.title ?? '';
}

function cancelRename(): void {
    editingId.value = null;
    editTitle.value = '';
}

async function saveRename(conversation: AiConversationSummary): Promise<void> {
    const title = editTitle.value.trim();

    if (title === '') {
        error.value = 'El título no puede estar vacío.';
        return;
    }

    try {
        const result = await ai.renameConversation(conversation.id, title);
        conversations.value = conversations.value.map((entry) =>
            entry.id === conversation.id ? { ...entry, title: result.title } : entry,
        );
        cancelRename();
    } catch (e) {
        error.value = readError(e, 'No fue posible renombrar la conversación.');
    }
}

/**
 * Delete a conversation after an explicit confirmation.
 *
 * The confirmation names the thread so it is unambiguous which one goes, and
 * the deletion is the server's: it removes this thread's files and nothing that
 * belongs to a business record.
 */
async function removeConversation(conversation: AiConversationSummary): Promise<void> {
    const label = conversation.title || 'esta conversación';

    if (!window.confirm(`¿Eliminar «${label}»? Esta acción no se puede deshacer.`)) {
        return;
    }

    try {
        await ai.deleteConversation(conversation.id);

        writeDraft(currentUserId(), conversation.id, '');
        conversations.value = conversations.value.filter((entry) => entry.id !== conversation.id);

        if (selectedId.value === conversation.id) {
            startNewConversation();
        }
    } catch (e) {
        error.value = readError(e, 'No fue posible eliminar la conversación.');
    }
}

async function selectConversation(id: number): Promise<void> {
    // A draft typed for the thread being left is saved before it is replaced.
    persistDraft();

    selectedId.value = id;
    persistSelected();
    orphanProposals.value = [];
    sources.value = [];
    artifacts.value = [];
    attachments.value = [];
    error.value = null;
    confirmation.value = null;
    drawerOpen.value = false;

    // Dictation belongs to the turn being abandoned, not to the page. Recording
    // continues in the background otherwise, and the next thing captured is
    // typed into a conversation the person never chose.
    dictation.abandon();

    try {
        const payload = await ai.history(id);

        const { byMessage, orphan } = groupArtifactsByMessage(payload.artifacts ?? []);
        const { byMessage: proposalsByMessage, orphan: orphanProposalsLoaded } = groupProposalsByMessage(payload.proposals ?? []);

        messages.value = payload.messages
            // The transcript is the conversation between the person and the
            // assistant; tool rows are internal machinery, not turns.
            .filter((message) => message.role === 'user' || message.role === 'assistant')
            .map((message) => ({
                ...message,
                // §10: an older server omits `status`; treat it as completed.
                status: message.status ?? 'completed',
                // Files this reply produced, so they render under it rather than
                // at the foot of the page.
                artifacts: typeof message.id === 'number' ? (byMessage.get(message.id) ?? []) : [],
                // Proposals this question asked for, inline with it.
                proposals: typeof message.id === 'number' ? (proposalsByMessage.get(message.id) ?? []) : [],
            }));

        // Files with no reply to sit beside go to the collapsed section.
        artifacts.value = orphan;
        // Proposals with no message to sit beside (created before this migration)
        // go to the secondary section.
        orphanProposals.value = orphanProposalsLoaded;
        attachments.value = payload.attachments ?? [];
    } catch (e) {
        messages.value = [];
        artifacts.value = [];
        orphanProposals.value = [];
        attachments.value = [];
        // The thread is gone (deleted here or elsewhere) or unreadable. Forget
        // the pointer instead of reopening a dead id on every reload.
        selectedId.value = null;
        persistSelected();
        error.value = readError(e, 'No fue posible cargar la conversación.');
    }

    // The draft this person had for this thread, and the latest turn in view.
    loadDraft();
    void scrollToLatest();
}

function startNewConversation(): void {
    // Keep the text typed for the thread being left.
    persistDraft();

    selectedId.value = null;
    // Forget the thread pointer too: reopening the page after "Nueva" must not
    // jump back into the conversation the person just left.
    persistSelected();
    messages.value = [];
    orphanProposals.value = [];
    sources.value = [];
    artifacts.value = [];
    attachments.value = [];
    error.value = null;
    confirmation.value = null;
    drawerOpen.value = false;

    dictation.abandon();
    loadDraft();
}

/**
 * Ask. The backend creates the conversation when none is selected, so the id
 * comes back on the first turn rather than from a separate create call.
 *
 * `retry` sends a question that already failed, reusing its `client_turn_id`.
 * That is what makes the retry the same attempt rather than a second one: the
 * server updates the failed row in place, and if the first attempt had in fact
 * completed it replays the stored answer instead of running the turn again.
 */
async function send(retry?: AiMessagePayload): Promise<void> {
    const question = (retry?.content ?? draft.value).trim();

    if (question === '' || sending.value) {
        return;
    }

    // Every attempt carries an id the server can recognise. A retry keeps the
    // one it was first sent with; a new question gets a fresh one.
    const clientTurnId = retry?.client_turn_id ?? newClientTurnId();

    const isThisTurn = (message: AiMessagePayload): boolean =>
        message.role === 'user' && message.client_turn_id === clientTurnId;

    sending.value = true;
    error.value = null;
    confirmation.value = null;

    if (retry === undefined) {
        draft.value = '';
        // The question is on its way, so the stored draft is no longer pending.
        // The previous thread's key is cleared too: the id may change below.
        persistDraft();
        writeDraft(currentUserId(), selectedId.value, '');

        // Rendered immediately so the question stays on screen while the model
        // works. Its state is part of the bubble, so a failure survives a reload
        // and can be retried from the transcript.
        messages.value = [
            ...messages.value,
            {
                role: 'user',
                content: question,
                provider: null,
                model: null,
                created_at: null,
                proposals: [],
                status: 'processing',
                client_turn_id: clientTurnId,
            },
        ];
    } else {
        // A retry reuses the bubble that failed rather than adding a duplicate.
        messages.value = messages.value.map((message) =>
            message === retry ? { ...message, status: 'processing', client_turn_id: clientTurnId, error: null } : message,
        );
    }

    void scrollToLatest(true);

    try {
        const turn = await ai.ask(question, selectedId.value, clientTurnId);

        selectedId.value = turn.conversation_id;
        persistSelected();
        collectSources(turn.tools);

        // The attempt is now history: its state is what a reload will show.
        messages.value = messages.value.map((message) =>
            isThisTurn(message) ? { ...message, status: 'completed', error: null } : message,
        );

        const turnArtifacts = turn.artifacts ?? [];

        if (turn.reply) {
            messages.value = [
                ...messages.value,
                {
                    role: 'assistant',
                    content: turn.reply,
                    provider: turn.provider,
                    model: turn.model,
                    created_at: null,
                    // The files this answer produced sit with it.
                    artifacts: turnArtifacts,
                },
            ];
        }

        // Attach any proposals to the user message that asked for them,
        // whether or not the turn produced a textual reply.
        if (turn.proposals.length > 0) {
            messages.value = messages.value.map((message) =>
                isThisTurn(message) ? { ...message, proposals: turn.proposals } : message,
            );
        } else if (turnArtifacts.length > 0) {
            // No prose to sit beside: the files go to the collapsed section.
            mergeArtifacts(turnArtifacts);
        }

        await loadConversations();
        void scrollToLatest();
    } catch (e) {
        // The question stays on screen, marked failed, with its own id. That is
        // the retry the person can act on: it survives a reload and does not
        // cost them the text they typed.
        const failure = readError(e, 'No fue posible completar la consulta.');

        // A first question creates its conversation before the provider is
        // asked. Adopting that id on failure is what lets an F5 show the failed
        // turn and makes the retry land in the same thread, not a new one.
        const failedId = conversationIdFromError(e);

        if (failedId !== null && selectedId.value === null) {
            selectedId.value = failedId;
            persistSelected();
            void loadConversations();
        }

        messages.value = messages.value.map((message) =>
            isThisTurn(message) ? { ...message, status: 'failed', error: failure } : message,
        );

        error.value = failure;
    } finally {
        sending.value = false;
    }
}

/**
 * Pull source links out of the turn's tool results so the answer can show what
 * it was based on. Only `web_search` results carry URLs; anything else is
 * internal data and is not rendered as a citation.
 */
function collectSources(tools: { tool: string; output: unknown }[] | undefined): void {
    if (!tools) {
        sources.value = [];
        return;
    }

    const found: { title: string; url: string; domain: string }[] = [];

    for (const entry of tools) {
        if (entry.tool !== 'web_search' || typeof entry.output !== 'object' || entry.output === null) {
            continue;
        }

        const results = (entry.output as { results?: unknown }).results;

        if (!Array.isArray(results)) {
            continue;
        }

        for (const item of results) {
            if (item && typeof item === 'object' && typeof (item as { url?: unknown }).url === 'string') {
                const row = item as { title?: string; url: string; domain?: string };
                found.push({ title: row.title || row.url, url: row.url, domain: row.domain ?? '' });
            }
        }
    }

    sources.value = found;
}

async function confirmProposal(id: number): Promise<void> {
    error.value = null;
    confirmation.value = null;

    try {
        const result = await ai.confirmProposal(id);

        // Remove from orphan proposals
        orphanProposals.value = orphanProposals.value.filter((proposal) => proposal.id !== id);
        // Also remove from inline proposals on messages
        messages.value = messages.value.map((message) => ({
            ...message,
            proposals: (message.proposals ?? []).filter((proposal) => proposal.id !== id),
        }));

        confirmation.value = describeResult(result.tool, result.result);
        await refreshIfSelected();
    } catch (e) {
        error.value = readError(e, 'No fue posible confirmar la propuesta.');
    }
}

async function rejectProposal(id: number): Promise<void> {
    error.value = null;

    try {
        await ai.rejectProposal(id);

        // Remove from orphan proposals
        orphanProposals.value = orphanProposals.value.filter((proposal) => proposal.id !== id);
        // Also remove from inline proposals on messages
        messages.value = messages.value.map((message) => ({
            ...message,
            proposals: (message.proposals ?? []).filter((proposal) => proposal.id !== id),
        }));
    } catch (e) {
        error.value = readError(e, 'No fue posible rechazar la propuesta.');
    }
}

async function refreshIfSelected(): Promise<void> {
    if (selectedId.value !== null) {
        const payload = await ai.history(selectedId.value);

        const { byMessage, orphan } = groupArtifactsByMessage(payload.artifacts ?? []);
        const { byMessage: proposalsByMessage, orphan: orphanProposalsLoaded } = groupProposalsByMessage(payload.proposals ?? []);

        messages.value = payload.messages
            .filter((message) => message.role === 'user' || message.role === 'assistant')
            .map((message) => ({
                ...message,
                status: message.status ?? 'completed',
                artifacts: typeof message.id === 'number' ? (byMessage.get(message.id) ?? []) : [],
                proposals: typeof message.id === 'number' ? (proposalsByMessage.get(message.id) ?? []) : [],
            }));

        artifacts.value = orphan;
        orphanProposals.value = orphanProposalsLoaded;
        attachments.value = payload.attachments ?? [];
    }
}

/**
 * Merge new artifacts into the ones already shown.
 *
 * Keyed by id, so a turn or a reload that reports a file already on screen does
 * not produce a second identical card.
 */
function mergeArtifacts(incoming: AiArtifact[]): void {
    if (incoming.length === 0) {
        return;
    }

    const seen = new Set(artifacts.value.map((artifact) => artifact.id));

    for (const artifact of incoming) {
        if (!seen.has(artifact.id)) {
            artifacts.value = [...artifacts.value, artifact];
            seen.add(artifact.id);
        }
    }
}

/** The server's own words about a confirmed write, or a plain acknowledgement. */
function describeResult(tool: string, result: unknown): string {
    if (result !== null && typeof result === 'object') {
        const message = (result as { message?: unknown }).message;

        if (typeof message === 'string' && message.trim() !== '') {
            return message;
        }
    }

    return `Acción aplicada: ${tool}.`;
}

/** Surface the server's own words; they are already free of internals. */
function readError(e: unknown, fallback: string): string {
    const message = (e as { message?: string } | null)?.message;
    return message !== undefined && message !== '' ? message : fallback;
}

/**
 * The conversation a failed turn belongs to, when the server reported it.
 *
 * Only used when no thread is selected yet: the backend creates the conversation
 * before asking the provider, so a failure on the first question would otherwise
 * leave that failed row unreachable from the page.
 */
function conversationIdFromError(e: unknown): number | null {
    const payload = (e as { payload?: { conversation_id?: unknown } } | null)?.payload;
    const id = payload?.conversation_id;

    return typeof id === 'number' && Number.isInteger(id) && id > 0 ? id : null;
}

/**
 * Append dictated speech to the draft.
 *
 * The transcript goes into the composer rather than being submitted: speech
 * recognition mishears names, numbers and accents, and sending an unedited
 * guess would put a wrong figure into a conversation about money.
 */
function appendDictation(text: string): void {
    const spoken = text.trim();

    if (spoken === '') {
        return;
    }

    draft.value = draft.value === '' ? spoken : `${draft.value} ${spoken}`;
}

/** Read one reply aloud. */
function readAloud(content: string): void {
    playback.speak(content);
}

function formatDate(value: string | null): string {
    if (!value) {
        return '';
    }

    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString('es-CO');
}
</script>

<template>
    <div class="cdh-ai">
        <header class="cdh-ai__header">
            <div>
                <h1 class="cdh-ai__title">Asistente de IA</h1>
                <p class="cdh-ai__status" :class="{ 'cdh-ai__status--off': !enabled }">{{ availability }}</p>
            </div>

            <!-- Mobile-first: the conversation list is a drawer that opens on
                 demand, so the chat is the first thing on a phone screen. -->
            <button
                type="button"
                class="cdh-ai__drawer-toggle"
                :aria-expanded="drawerOpen"
                aria-controls="cdh-ai-conversations"
                @click="drawerOpen = !drawerOpen"
            >
                <i class="bi bi-chat-square-text" aria-hidden="true" />
                Conversaciones
            </button>
        </header>

        <AppAlert v-if="error" variant="danger" :title="error" class="mb-3" />

        <AppAlert
            v-if="confirmation"
            variant="success"
            :message="confirmation"
            class="mb-3"
            data-testid="confirmation"
        />

        <div v-if="uploadError" class="mb-3" data-testid="upload-error">
            <AppAlert variant="danger" :title="uploadError" />
        </div>

        <div v-if="longRunningError" class="mb-3" data-testid="long-running-error">
            <AppAlert variant="danger" :title="longRunningError" />
        </div>

        <form
            v-if="briefOpen"
            class="mb-3 rounded-lg border border-slate-200 p-3"
            data-testid="generate-form"
            @submit.prevent="startGeneration"
        >
            <div class="mb-2 flex flex-wrap gap-2">
                <label class="text-xs text-slate-600">
                    Formato
                    <select v-model="briefFormat" class="ml-1 rounded border border-slate-300 px-2 py-1" data-testid="generate-format">
                        <option value="pdf">PDF</option>
                        <option value="docx">DOCX</option>
                        <option value="xlsx">XLSX</option>
                        <option value="csv">CSV</option>
                        <option value="md">Markdown</option>
                        <option value="txt">Texto</option>
                    </select>
                </label>

                <label class="text-xs text-slate-600">
                    Nombre
                    <input
                        v-model="briefFilename"
                        type="text"
                        class="ml-1 rounded border border-slate-300 px-2 py-1"
                        data-testid="generate-filename"
                    />
                </label>
            </div>

            <label class="visually-hidden" for="cdh-generate-brief">Qué debe contener el documento</label>
            <textarea
                id="cdh-generate-brief"
                v-model="briefText"
                rows="3"
                class="w-full rounded border border-slate-300 p-2 text-sm"
                placeholder="Describe qué debe contener el documento…"
                data-testid="generate-brief"
            />

            <div class="mt-2 flex gap-2">
                <AppButton type="submit" data-testid="generate-submit">Generar</AppButton>
                <AppButton type="button" variant="link" size="sm" @click="briefOpen = false">Cancelar</AppButton>
            </div>
        </form>

        <!--
            Background work travels inside the conversation column, below the
            transcript, so on a phone the chat is not pushed off the screen by a
            task panel. It is the same `tasks` controller either way.
        -->
        <SuggestionHint
            v-if="hint.hasSuggestion.value && hint.suggestion.value"
            class="mb-3"
            :suggestion="hint.suggestion.value"
            :busy="hint.busy.value"
            @accept="acceptHint"
            @dismiss="dismissHint"
        />

        <div class="cdh-ai__body">
            <aside id="cdh-ai-conversations" class="cdh-ai__sidebar" :class="{ 'cdh-ai__sidebar--open': drawerOpen }" aria-label="Conversaciones">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="cdh-ai__sidebar-title">Conversaciones</h2>
                    <AppButton variant="link" size="sm" @click="startNewConversation">Nueva</AppButton>
                </div>

                <AppEmptyState v-if="conversations.length === 0" title="Sin conversaciones" icon="bi-chat-square-text" />

                <ul v-else class="cdh-ai__conversations">
                    <li v-for="conversation in conversations" :key="conversation.id" class="cdh-ai__conversation-row">
                        <form
                            v-if="editingId === conversation.id"
                            class="cdh-ai__conversation-edit"
                            @submit.prevent="saveRename(conversation)"
                        >
                            <input
                                v-model="editTitle"
                                type="text"
                                class="form-control form-control-sm"
                                :aria-label="`Nuevo título para ${conversation.title || 'la conversación'}`"
                                data-test="conversation-title-input"
                                @keydown.esc="cancelRename"
                            />
                            <AppButton type="submit" size="sm" variant="primary" data-test="conversation-save">Guardar</AppButton>
                            <AppButton type="button" size="sm" variant="link" @click="cancelRename">Cancelar</AppButton>
                        </form>

                        <div v-else class="cdh-ai__conversation-item">
                            <button
                                type="button"
                                class="cdh-ai__conversation"
                                :class="{ 'cdh-ai__conversation--active': conversation.id === selectedId }"
                                :aria-current="conversation.id === selectedId ? 'true' : undefined"
                                @click="selectConversation(conversation.id)"
                            >
                                <span class="cdh-ai__conversation-title">{{ conversation.title || 'Sin título' }}</span>
                                <span class="cdh-ai__conversation-date">{{ formatDate(conversation.created_at) }}</span>
                            </button>

                            <div class="cdh-ai__conversation-actions">
                                <button
                                    type="button"
                                    class="cdh-ai__conversation-action"
                                    :aria-label="`Renombrar ${conversation.title || 'la conversación'}`"
                                    title="Renombrar"
                                    data-test="conversation-rename"
                                    @click="startRename(conversation)"
                                >
                                    <i class="bi bi-pencil" aria-hidden="true" />
                                </button>
                                <button
                                    type="button"
                                    class="cdh-ai__conversation-action cdh-ai__conversation-action--danger"
                                    :aria-label="`Eliminar ${conversation.title || 'la conversación'}`"
                                    title="Eliminar"
                                    data-test="conversation-delete"
                                    @click="removeConversation(conversation)"
                                >
                                    <i class="bi bi-trash" aria-hidden="true" />
                                </button>
                            </div>
                        </div>
                    </li>
                </ul>
            </aside>

            <section class="cdh-ai__main" aria-label="Conversación">
                <div
                    ref="transcriptEl"
                    class="cdh-ai__transcript"
                    role="log"
                    aria-live="polite"
                    @scroll="onTranscriptScroll"
                >
                    <AppEmptyState
                        v-if="messages.length === 0"
                        title="Empieza una conversación"
                        icon="bi-stars"
                        action-label="Pregunta por la cartera, genera un informe o redacta un documento."
                    />

                    <div
                        v-for="(message, index) in messages"
                        :key="`${message.created_at ?? 'local'}-${index}`"
                        class="cdh-ai__message"
                        :class="message.role === 'user' ? 'cdh-ai__message--user' : 'cdh-ai__message--assistant'"
                    >
                        <SafeMarkdown v-if="message.role === 'assistant'" :content="message.content" />
                        <p v-else class="cdh-ai__message-body">{{ message.content }}</p>

                        <!-- A failed question is kept in place and can be retried
                             by its own attempt id; reloading the thread shows the
                             same state rather than losing what was asked. -->
                        <div
                            v-if="message.role === 'user' && message.status === 'failed'"
                            class="cdh-ai__message-failed"
                            data-testid="failed-turn"
                        >
                            <p class="cdh-ai__message-error">{{ message.error || 'No se completó esta consulta.' }}</p>
                            <AppButton
                                type="button"
                                size="sm"
                                variant="secondary"
                                :disabled="sending"
                                @click="send(message)"
                            >
                                Reintentar
                            </AppButton>
                        </div>

                        <div class="cdh-ai__message-tools">
                            <SpeakButton
                                v-if="message.role === 'assistant'"
                                :playback="playback"
                                @speak="readAloud(message.content)"
                            />
                            <p v-if="message.created_at" class="cdh-ai__message-meta">
                                {{ formatDate(message.created_at) }}
                            </p>
                        </div>

                        <!-- The files this answer produced, right below it. -->
                        <div
                            v-if="message.role === 'assistant' && message.artifacts && message.artifacts.length"
                            class="cdh-ai__message-artifacts"
                        >
                            <ArtifactCard v-for="artifact in message.artifacts" :key="artifact.id" :artifact="artifact" />
                        </div>
                        <!-- Proposals this question asked for, inline with it. -->
                        <div
                            v-if="message.role === 'user' && message.proposals && message.proposals.length"
                            class="cdh-ai__message-proposals"
                        >
                            <div
                                v-for="proposal in message.proposals"
                                :key="proposal.id"
                                class="cdh-ai__proposal cdh-ai__proposal--inline"
                            >
                                <p class="cdh-ai__proposal-tool">{{ proposal.tool }}</p>
                                <pre class="cdh-ai__proposal-args">{{ JSON.stringify(proposal.arguments, null, 2) }}</pre>
                                <div class="d-flex gap-2">
                                    <AppButton size="sm" @click="confirmProposal(proposal.id)">Confirmar</AppButton>
                                    <AppButton size="sm" variant="secondary" @click="rejectProposal(proposal.id)">Descartar</AppButton>
                                </div>
                            </div>
                        </div>
                    </div>

                    <p v-if="sending" class="cdh-ai__pending">Consultando…</p>

                    <!-- Work already queued for this thread, shown where the
                         question was asked rather than only in the panel above. -->
                    <div v-if="inlineTasks.length" class="cdh-ai__inline-tasks" data-testid="inline-tasks">
                        <div v-for="task in inlineTasks" :key="task.id" class="cdh-ai__inline-task">
                            <span class="cdh-spinner" aria-hidden="true" />
                            <span class="cdh-ai__inline-task-label">{{ taskLabel(task) }}</span>
                            <span class="cdh-ai__inline-task-progress">{{ task.progress }}%</span>
                        </div>
                    </div>
                </div>

                <!-- Files with no reply to sit beside — a background task's output,
                     or a proposal-only turn. Collapsed so the transcript stays the
                     transcript. -->
                <details v-if="artifacts.length > 0" class="cdh-ai__artifacts" data-testid="artifacts">
                    <summary class="cdh-ai__artifacts-title">Archivos anteriores ({{ artifacts.length }})</summary>
                    <ArtifactCard v-for="artifact in artifacts" :key="artifact.id" :artifact="artifact" />
                </details>

                <!-- Proposals with no message to sit beside (created before this migration). -->
                <details v-if="orphanProposals.length > 0" class="cdh-ai__proposals" data-testid="orphan-proposals">
                    <summary class="cdh-ai__proposals-title">Propuestas anteriores ({{ orphanProposals.length }})</summary>
                    <div
                        v-for="proposal in orphanProposals"
                        :key="proposal.id"
                        class="cdh-ai__proposal"
                    >
                        <p class="cdh-ai__proposal-tool">{{ proposal.tool }}</p>
                        <pre class="cdh-ai__proposal-args">{{ JSON.stringify(proposal.arguments, null, 2) }}</pre>
                        <div class="d-flex gap-2">
                            <AppButton size="sm" @click="confirmProposal(proposal.id)">Confirmar</AppButton>
                            <AppButton size="sm" variant="secondary" @click="rejectProposal(proposal.id)">Descartar</AppButton>
                        </div>
                    </div>
                </details>

                <!-- Files the person attached. Restored from the server rather
                     than from memory, so a thread that was given a spreadsheet
                     still shows it after a reload or on another device. -->
                <div v-if="attachments.length > 0" class="cdh-ai__attachments" data-testid="attachments">
                    <h3 class="cdh-ai__artifacts-title">Adjuntos</h3>
                    <ul>
                        <li v-for="attachment in attachments" :key="attachment.id" class="cdh-ai__attachment">
                            <i class="bi bi-paperclip" aria-hidden="true" />
                            <span class="cdh-ai__attachment-name">{{ attachment.name }}</span>
                            <span v-if="attachment.status === 'unreadable'" class="cdh-ai__attachment-note">No se pudo procesar</span>
                            <span v-else-if="attachment.status !== 'processed'" class="cdh-ai__attachment-note">Procesando…</span>
                        </li>
                    </ul>
                </div>

                <!-- Background work, kept below the conversation rather than
                     above it, so on a phone the first thing seen is the chat. -->
                <TaskPanel class="cdh-ai__tasks" :controller="tasks" :conversation-id="selectedId" />

                <div v-if="sources.length > 0" class="cdh-ai__sources">
                    <h3 class="cdh-ai__sources-title">Fuentes</h3>
                    <ul>
                        <li v-for="source in sources" :key="source.url">
                            <a :href="source.url" target="_blank" rel="noopener noreferrer">{{ source.title }}</a>
                            <span v-if="source.domain" class="cdh-ai__source-domain">{{ source.domain }}</span>
                        </li>
                    </ul>
                </div>

                <form class="cdh-ai__composer" @submit.prevent="send()">
                    <label for="cdh-ai-input" class="visually-hidden">Mensaje para el asistente</label>
                    <textarea
                        id="cdh-ai-input"
                        v-model="draft"
                        class="cdh-ai__input"
                        rows="3"
                        placeholder="Escribe tu consulta…"
                        :disabled="sending || loading"
                    />
                    <div class="cdh-ai__composer-tools">
                        <AttachmentPicker
                            :busy="uploading"
                            :disabled="sending || loading"
                            @choose="attachFile"
                        />

                        <AppButton
                            type="button"
                            variant="link"
                            size="sm"
                            :disabled="sending || loading"
                            data-testid="research-button"
                            @click="startResearch"
                        >
                            Investigar
                        </AppButton>

                        <AppButton
                            type="button"
                            variant="link"
                            size="sm"
                            :disabled="sending || loading"
                            data-testid="generate-button"
                            @click="briefOpen = !briefOpen"
                        >
                            Generar documento
                        </AppButton>
                    </div>

                    <MicButton :dictation="dictation" @send="appendDictation" />

                    <AppButton type="submit" :busy="sending" :disabled="draft.trim() === ''">Enviar</AppButton>
                </form>
            </section>
        </div>
    </div>
</template>

<style scoped>
.cdh-ai {
    overflow-x: hidden;
}

.cdh-ai__header {
    margin-bottom: var(--cdh-space-4);
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--cdh-space-3);
    flex-wrap: wrap;
}

.cdh-ai__drawer-toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.4rem 0.7rem;
    border: 1px solid var(--cdh-border);
    border-radius: var(--cdh-radius-md, 0.375rem);
    background: var(--cdh-surface);
    color: inherit;
    font: inherit;
    font-size: 0.8125rem;
}

.cdh-ai__title {
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0;
}

.cdh-ai__status {
    color: var(--cdh-text-muted);
    font-size: 0.875rem;
    margin: 0.25rem 0 0;
}

.cdh-ai__status--off {
    color: var(--cdh-warning, #b45309);
}

.cdh-ai__body {
    display: grid;
    gap: var(--cdh-space-4);
    grid-template-columns: 1fr;
}

.cdh-ai__sidebar-title,
.cdh-ai__sources-title,
.cdh-ai__artifacts-title,
.cdh-ai__proposals-title {
    font-size: 0.875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--cdh-text-muted);
    margin: 0;
}

.cdh-ai__conversations {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.cdh-ai__conversation {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    width: 100%;
    text-align: left;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--cdh-border);
    border-radius: var(--cdh-radius-md, 0.375rem);
    background: var(--cdh-surface);
    color: inherit;
}

.cdh-ai__conversation--active {
    border-color: var(--cdh-primary);
    background: var(--cdh-surface-sunken);
}

.cdh-ai__conversation-title {
    font-size: 0.875rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.cdh-ai__conversation-date {
    font-size: 0.75rem;
    color: var(--cdh-text-muted);
}

/* Mobile-first: the conversation list is hidden until the drawer is opened. */
.cdh-ai__sidebar {
    display: none;
}

.cdh-ai__sidebar--open {
    display: block;
}

.cdh-ai__main {
    display: flex;
    flex-direction: column;
    gap: var(--cdh-space-3);
    min-width: 0;
}

.cdh-ai__transcript {
    border: 1px solid var(--cdh-border);
    border-radius: var(--cdh-radius-lg, 0.5rem);
    background: var(--cdh-surface);
    padding: var(--cdh-space-4);
    display: flex;
    flex-direction: column;
    gap: var(--cdh-space-3);
    /* Bounded so a long transcript scrolls inside the panel instead of
       pushing the composer off a mobile viewport. */
    max-height: min(60vh, 32rem);
    overflow-y: auto;
}

.cdh-ai__message {
    max-width: 85%;
    min-width: 0;
    padding: 0.625rem 0.875rem;
    border-radius: var(--cdh-radius-lg, 0.5rem);
    overflow-wrap: anywhere;
}

.cdh-ai__message--user {
    align-self: flex-end;
    background: var(--cdh-primary);
    color: #fff;
}

.cdh-ai__message--assistant {
    align-self: flex-start;
    background: var(--cdh-surface-sunken);
}

.cdh-ai__message-body {
    margin: 0;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.cdh-ai__message-meta {
    margin: 0.25rem 0 0;
    font-size: 0.6875rem;
    opacity: 0.75;
}

/* Files generated by an answer, shown under it. */
.cdh-ai__message-artifacts {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    margin-top: 0.4rem;
}

.cdh-ai__artifacts > summary {
    cursor: pointer;
}

.cdh-ai__pending {
    margin: 0;
    color: var(--cdh-text-muted);
    font-size: 0.875rem;
}

.cdh-ai__sources,
.cdh-ai__artifacts,
.cdh-ai__attachments,
.cdh-ai__proposals {
    border: 1px solid var(--cdh-border);
    border-radius: var(--cdh-radius-lg, 0.5rem);
    padding: var(--cdh-space-3);
}

.cdh-ai__attachments ul {
    list-style: none;
    margin: 0.5rem 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.cdh-ai__attachment {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    min-width: 0;
    font-size: 0.875rem;
}

.cdh-ai__attachment-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.cdh-ai__attachment-note {
    flex: 0 0 auto;
    color: var(--cdh-text-muted);
    font-size: 0.75rem;
}

.cdh-ai__tasks {
    margin-top: var(--cdh-space-2);
}

.cdh-ai__artifacts {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.cdh-ai__inline-tasks {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    border-top: 1px dashed var(--cdh-border);
    padding-top: var(--cdh-space-2);
}

.cdh-ai__inline-task {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-width: 0;
    font-size: 0.8125rem;
    color: var(--cdh-text-muted);
}

.cdh-ai__inline-task-label {
    flex: 1 1 auto;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.cdh-ai__inline-task-progress {
    flex: 0 0 auto;
}

.cdh-ai__sources ul {
    list-style: none;
    margin: 0.5rem 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    font-size: 0.875rem;
}

.cdh-ai__source-domain {
    color: var(--cdh-text-muted);
    margin-left: 0.375rem;
    font-size: 0.75rem;
}

.cdh-ai__proposals-note {
    margin: 0.25rem 0 0.75rem;
    font-size: 0.8125rem;
    color: var(--cdh-text-muted);
}

.cdh-ai__proposal {
    border: 1px solid var(--cdh-border);
    border-radius: var(--cdh-radius-md, 0.375rem);
    padding: 0.75rem;
}

.cdh-ai__proposal-tool {
    margin: 0 0 0.375rem;
    font-weight: 600;
    font-size: 0.875rem;
}

.cdh-ai__proposal-args {
    margin: 0 0 0.5rem;
    font-size: 0.75rem;
    max-height: 12rem;
    overflow: auto;
}

.cdh-ai__composer-tools {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.cdh-ai__composer {
    display: flex;
    gap: var(--cdh-space-2);
    align-items: flex-start;
    flex-wrap: wrap;
    /* Always within reach on a phone: the composer sticks to the bottom of the
       viewport once it would otherwise scroll away. */
    position: sticky;
    bottom: 0;
    z-index: 2;
    background: var(--cdh-surface);
    padding-bottom: calc(var(--cdh-space-2) + env(safe-area-inset-bottom, 0px));
}

.cdh-ai__message-failed {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: var(--cdh-space-2);
    margin-top: var(--cdh-space-1);
}

.cdh-ai__message-error {
    margin: 0;
    font-size: 0.8125rem;
    color: var(--cdh-danger, #b42318);
}

.cdh-ai__message-tools {
    display: flex;
    align-items: center;
    gap: var(--cdh-space-2);
    margin-top: var(--cdh-space-1);
}

.cdh-ai__message-tools .cdh-ai__message-meta {
    margin: 0;
}

.cdh-ai__input {
    flex: 1;
    min-width: 0;
    border: 1px solid var(--cdh-border);
    border-radius: var(--cdh-radius-md, 0.375rem);
    padding: 0.625rem 0.75rem;
    font: inherit;
    resize: vertical;
}

/* The sidebar becomes a column above the transcript rather than disappearing:
   the conversation switcher is how a returning user resumes their work. */
@media (min-width: 992px) {
    .cdh-ai__body {
        grid-template-columns: 16rem 1fr;
    }

    /* The drawer toggle is a mobile affordance; on desktop the sidebar is a
       permanent column. */
    .cdh-ai__drawer-toggle {
        display: none;
    }

    .cdh-ai__sidebar {
        display: block;
        max-height: min(60vh, 32rem);
        overflow-y: auto;
    }
}

/* Small phones — 360 to 430 CSS pixels. The transcript is where a long answer
   lands, so it gets the space back that padding was taking, and the composer
   stops wrapping into a column narrower than a word. */
@media (max-width: 430px) {
    .cdh-ai__transcript {
        padding: var(--cdh-space-3);
    }

    .cdh-ai__message {
        max-width: 94%;
        padding: 0.5rem 0.65rem;
    }

    .cdh-ai__composer {
        flex-direction: column;
        align-items: stretch;
    }

    .cdh-ai__composer .cdh-ai__input {
        width: 100%;
    }
}
</style>
