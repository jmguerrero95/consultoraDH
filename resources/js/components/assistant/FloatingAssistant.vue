<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import SafeMarkdown from '@/components/ui/SafeMarkdown.vue';
import ArtifactCard from '@/components/assistant/ArtifactCard.vue';
import { ai } from '@/services/ai';
import { useAuthStore } from '@/stores/auth';

import type { AiArtifact, AiProposal, AiStatusPayload, AiToolResult } from '@/services/ai';

/**
 * The assistant, available from every screen.
 *
 * ## Why it exists and what it reuses
 *
 * Before this, the assistant lived only at `/asistente`: a person had to leave the
 * screen they were working on, lose the context they were reading, and remember to
 * come back. This widget is the same assistant in a panel that never leaves the
 * page. It **reuses** the same service (`@/services/ai`) and the same endpoints as
 * the full screen — there is no second conversation store and no second protocol —
 * so a question asked here is the same question, with the same tools, proposals and
 * confirmation flow, as one asked on `/asistente`. The header links to that page for
 * the wider view.
 *
 * ## What survives a reload (BUG-020-R2)
 *
 * The panel remembers *which* conversation is open, not a copy of it. The id is
 * the only thing kept in the browser; the messages are re-read from the server,
 * so there is exactly one store of the conversation and a reload shows the real
 * thread rather than a local echo that could have drifted from it.
 *
 * ## Two rules it inherits from the full screen
 *
 * - An empty reply is never rendered as a success. The server refuses to produce
 *   one (BUG-018) and this panel only appends a bubble when there is text.
 * - A refusal is shown in the server's own words, which are already free of
 *   internals, rather than replaced with a generic apology.
 *
 * It is mounted once in the admin shell and shown only to someone who may use the
 * assistant, so its `status` call happens for those users only.
 */

const auth = useAuthStore();

const canUse = computed(() => auth.can('ai.use'));

/** The browser's own storage, and the only thing it is trusted with: a pointer. */
const STORAGE_CONVERSATION = 'cdh.assistant.conversation';
const STORAGE_OPEN = 'cdh.assistant.open';

const open = ref(false);
const fullscreen = ref(false);
const status = ref<AiStatusPayload | null>(null);
const statusLoaded = ref(false);

interface ChatMessage {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    model: string | null;
    /** A question the server refused: kept on screen, with a retry. */
    failed?: boolean;
    /** Read tools that were consulted to produce this answer. */
    tools?: AiToolResult[];
}

let messageSeq = 0;

const messages = ref<ChatMessage[]>([]);
const proposals = ref<AiProposal[]>([]);
/** Files this thread has produced: the PDF, the spreadsheet, the report. */
const artifacts = ref<AiArtifact[]>([]);
const draft = ref('');
const sending = ref(false);
const error = ref<string | null>(null);
/**
 * The server's own account of what a confirmed action did.
 *
 * Kept apart from `error` so a success is never shown in red, and every confirm
 * shows *something*: a confirmation that silently removed the card left the
 * person unsure whether the action had run at all.
 */
const confirmation = ref<string | null>(null);
const conversationId = ref<number | null>(null);
const scroller = ref<HTMLElement | null>(null);
const input = ref<HTMLInputElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

/** A file attached to this conversation, and how far its ingest has got. */
interface Attachment {
    id: number;
    name: string;
    status: string;
    progress: number;
    error: string | null;
}

const attachments = ref<Attachment[]>([]);
const attachmentTimers = new Map<number, number>();
let restored = false;

/**
 * BUG-019. The launcher is no longer gated on a successful status call. It used
 * to be hidden unless the last `/api/ai/status` had answered `ai_enabled: true`,
 * so a failed status request — a 500, a network error, an expired session —
 * silently removed the entry point for someone who is allowed to use the
 * assistant. Visibility now follows the permission alone (RBAC unchanged) and
 * whatever the status says is shown *inside* the panel. This holds the reason
 * that the status could not be read, so the panel can say so.
 */
const statusError = ref<string | null>(null);

/**
 * The reason the assistant may not answer, in the panel's own words.
 *
 * `null` when there is nothing to warn about, including while the status is
 * still loading: the header says "Comprobando…" for that case already.
 */
const notice = computed<{ variant: 'danger' | 'warning'; message: string } | null>(() => {
    if (statusError.value) {
        return { variant: 'danger', message: statusError.value };
    }

    if (!statusLoaded.value || !status.value) {
        return null;
    }

    if (status.value.ai_enabled !== true) {
        return { variant: 'warning', message: 'El asistente no está habilitado en este entorno.' };
    }

    if (status.value.state === 'none') {
        return { variant: 'warning', message: 'No hay ningún proveedor de IA configurado. Contacte al administrador.' };
    }

    if (status.value.state === 'quota_limited') {
        return { variant: 'warning', message: 'El proveedor de IA alcanzó su límite de uso. Puede reintentar en unos minutos.' };
    }

    if (status.value.state === 'unavailable') {
        return { variant: 'warning', message: 'Los proveedores de IA están temporalmente indisponibles. Puede reintentar en unos minutos.' };
    }

    return null;
});

const statusLabel = computed(() => {
    if (!status.value) {
        return 'Comprobando…';
    }

    switch (status.value.state) {
        case 'none':
            return 'Sin proveedor configurado';
        case 'unavailable':
            return 'No disponible ahora';
        case 'quota_limited':
            return 'Límite de uso alcanzado';
        case 'fallback':
            return `Respaldo${status.value.active_model ? ` · ${status.value.active_model}` : ''}`;
        default:
            return status.value.active_model ? `Operativo · ${status.value.active_model}` : 'Operativo';
    }
});

async function loadStatus(): Promise<void> {
    try {
        status.value = await ai.status();
        statusError.value = null;
    } catch (e) {
        // A failed status call must not remove the access point: keep the error
        // to show inside the panel and leave the launcher in place.
        status.value = null;
        statusError.value = readError(e, 'No fue posible consultar el estado del asistente. El acceso sigue disponible.');
    } finally {
        statusLoaded.value = true;
    }
}

function toggle(): void {
    open.value = !open.value;

    if (open.value) {
        void nextTick(() => input.value?.focus());
    }
}

function close(): void {
    open.value = false;
    fullscreen.value = false;
}

function toggleFullscreen(): void {
    fullscreen.value = !fullscreen.value;
}

function newConversation(): void {
    conversationId.value = null;
    messages.value = [];
    proposals.value = [];
    artifacts.value = [];
    attachments.value = [];
    error.value = null;
    confirmation.value = null;
    draft.value = '';
    persistConversation();
    void nextTick(() => input.value?.focus());
}

/**
 * Recover the conversation after a reload from the server, not from a local copy.
 *
 * The stored value is an id. The messages are always the server's, so the panel
 * cannot show a thread that no longer exists or that has moved on.
 */
async function restoreConversation(): Promise<void> {
    if (restored) {
        return;
    }

    restored = true;

    const stored = storedConversation();

    if (stored === null) {
        return;
    }

    conversationId.value = stored;

    try {
        const history = await ai.history(stored);

        messages.value = history.messages
            .filter((message) => message.role === 'user' || message.role === 'assistant')
            .map((message) => ({
                id: ++messageSeq,
                role: message.role as 'user' | 'assistant',
                content: message.content,
                model: message.model,
            }));

        // The files this thread produced travel with it, so a reload shows the
        // report where it was asked for rather than in a separate panel.
        artifacts.value = history.artifacts ?? [];

        scrollToEnd();
    } catch {
        // The thread is gone, or belongs to someone else now. Start clean rather
        // than show an error for something the person did not do.
        conversationId.value = null;
        messages.value = [];
        artifacts.value = [];
        persistConversation();
    }
}

async function send(retryText?: string): Promise<void> {
    const question = (retryText ?? draft.value).trim();

    if (question === '' || sending.value) {
        return;
    }

    sending.value = true;
    error.value = null;
    confirmation.value = null;

    if (retryText === undefined) {
        draft.value = '';
        messages.value = [...messages.value, { id: ++messageSeq, role: 'user', content: question, model: null }];
    } else {
        // A retry reuses the bubble that failed rather than adding a duplicate.
        messages.value = messages.value.map((message) =>
            message.role === 'user' && message.failed && message.content === question ? { ...message, failed: false } : message,
        );
    }

    scrollToEnd();

    try {
        const turn = await ai.ask(question, conversationId.value);

        conversationId.value = turn.conversation_id;
        persistConversation();
        proposals.value = turn.proposals;
        mergeArtifacts(turn.artifacts ?? []);

        // Only append when the server actually produced text: an empty bubble is
        // the failure this widget must not pretend is an answer.
        if (turn.reply && turn.reply.trim() !== '') {
            messages.value = [
                ...messages.value,
                { id: ++messageSeq, role: 'assistant', content: turn.reply, model: turn.model, tools: turn.tools ?? [] },
            ];
        } else if (turn.proposals.length === 0 && (turn.artifacts ?? []).length === 0) {
            markLastUserFailed();
            error.value = 'El asistente no devolvió una respuesta. Intente reformular la pregunta.';
        }

        scrollToEnd();
    } catch (e) {
        // Keep the question on screen, flagged, so a retry does not cost the
        // person their typing.
        markLastUserFailed();
        error.value = readError(e, 'No fue posible completar la consulta.');
    } finally {
        sending.value = false;
    }
}

/**
 * Merge new artifacts into the ones already shown.
 *
 * Keyed by id: a turn, or a reload, may report a file that is already on screen,
 * and a second identical card would look like a second file.
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

/** The server's own words about a confirmed action, or a plain acknowledgement. */
function describeResult(tool: string, result: unknown): string {
    if (result !== null && typeof result === 'object') {
        const message = (result as { message?: unknown }).message;

        if (typeof message === 'string' && message.trim() !== '') {
            return message;
        }
    }

    return `Acción aplicada: ${tool}.`;
}

function markLastUserFailed(): void {
    const next = [...messages.value];

    for (let index = next.length - 1; index >= 0; index -= 1) {
        if (next[index].role === 'user') {
            next[index] = { ...next[index], failed: true };

            break;
        }
    }

    messages.value = next;
}

function retry(message: ChatMessage): void {
    void send(message.content);
}

/** A conversation must exist before a file can belong to it. */
async function ensureConversation(): Promise<number> {
    if (conversationId.value !== null) {
        return conversationId.value;
    }

    const opened = await ai.openConversation();
    conversationId.value = opened.conversation_id;
    persistConversation();

    return opened.conversation_id;
}

async function onFileSelected(event: Event): Promise<void> {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];

    // Reset the input so selecting the same file twice still fires `change`.
    target.value = '';

    if (!file) {
        return;
    }

    error.value = null;

    try {
        const conversation = await ensureConversation();
        const { task } = await ai.uploadTask(file, conversation);

        attachments.value = [
            ...attachments.value,
            { id: task.id, name: task.name ?? file.name, status: task.status, progress: task.progress ?? 0, error: null },
        ];

        if (!open.value) {
            open.value = true;
        }

        pollAttachment(task.id);
    } catch (e) {
        error.value = readError(e, 'No fue posible adjuntar el archivo.');
    }
}

/**
 * Follow an upload until it finishes.
 *
 * A polling loop rather than a websocket: the same task endpoint the full screen
 * uses, and the result is on the server, so closing the panel does not lose it.
 */
function pollAttachment(id: number): void {
    const tick = async (): Promise<void> => {
        try {
            const { task } = await ai.task(id);

            attachments.value = attachments.value.map((attachment) =>
                attachment.id === id
                    ? { ...attachment, status: task.status, progress: task.progress ?? attachment.progress, error: task.error }
                    : attachment,
            );

            if (task.finished) {
                attachmentTimers.delete(id);

                return;
            }
        } catch {
            // A transient failure: leave the last known state and stop polling
            // rather than loop against an endpoint that is refusing.
            attachmentTimers.delete(id);

            return;
        }

        attachmentTimers.set(id, window.setTimeout(() => void tick(), 1500));
    };

    void tick();
}

function isFinishedStatus(state: string): boolean {
    return state === 'succeeded' || state === 'failed' || state === 'cancelled';
}

async function confirmProposal(id: number): Promise<void> {
    error.value = null;
    confirmation.value = null;

    try {
        const result = await ai.confirmProposal(id);
        proposals.value = proposals.value.filter((proposal) => proposal.id !== id);

        // Use the server's own account of what ran. Removing the card and saying
        // nothing left the person unsure whether the action had happened.
        confirmation.value = describeResult(result.tool, result.result);
    } catch (e) {
        error.value = readError(e, 'No fue posible confirmar la propuesta.');
    }
}

async function rejectProposal(id: number): Promise<void> {
    error.value = null;

    try {
        await ai.rejectProposal(id);
        proposals.value = proposals.value.filter((proposal) => proposal.id !== id);
    } catch (e) {
        error.value = readError(e, 'No fue posible rechazar la propuesta.');
    }
}

function readError(e: unknown, fallback: string): string {
    const message = (e as { message?: string } | null)?.message;

    return message !== undefined && message !== '' ? message : fallback;
}

function scrollToEnd(): void {
    void nextTick(() => {
        if (scroller.value) {
            scroller.value.scrollTop = scroller.value.scrollHeight;
        }
    });
}

/** A one-line description of a proposed write, for the confirmation card. */
function describeArguments(arguments_: Record<string, unknown>): string {
    const entries = Object.entries(arguments_);

    if (entries.length === 0) {
        return 'Sin argumentos';
    }

    return entries
        .map(([key, value]) => `${key}: ${typeof value === 'object' ? JSON.stringify(value) : String(value)}`)
        .join(' · ');
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Escape' || !open.value) {
        return;
    }

    // Escape leaves full screen first, then closes: a person in full screen who
    // presses Escape usually means to go back one step, not to lose the thread.
    if (fullscreen.value) {
        fullscreen.value = false;

        return;
    }

    close();
}

function persistConversation(): void {
    try {
        if (conversationId.value === null) {
            window.localStorage.removeItem(STORAGE_CONVERSATION);
        } else {
            window.localStorage.setItem(STORAGE_CONVERSATION, String(conversationId.value));
        }
    } catch {
        // Storage can be unavailable (private mode, policy). The panel still
        // works for this session; it just will not reopen the thread.
    }
}

function persistOpen(): void {
    try {
        window.localStorage.setItem(STORAGE_OPEN, open.value ? '1' : '0');
    } catch {
        // See above.
    }
}

function storedConversation(): number | null {
    try {
        const raw = window.localStorage.getItem(STORAGE_CONVERSATION);
        const value = raw === null ? Number.NaN : Number(raw);

        return Number.isInteger(value) && value > 0 ? value : null;
    } catch {
        return null;
    }
}

function storedOpen(): boolean {
    try {
        return window.localStorage.getItem(STORAGE_OPEN) === '1';
    } catch {
        return false;
    }
}

onMounted(() => {
    // The keydown listener is cheap and does not depend on permissions.
    document.addEventListener('keydown', onKeydown);

    if (canUse.value) {
        open.value = storedOpen();
        void loadStatus();
        void restoreConversation();
    }
});

onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown);

    for (const handle of attachmentTimers.values()) {
        window.clearTimeout(handle);
    }

    attachmentTimers.clear();
});

// BUG-019. The authenticated user — and with it the permission list — can
// arrive after this component has mounted: on a cold load the auth store is
// still restoring the session when the admin shell renders, so `canUse` is
// false on the first tick and only turns true later. Without this watcher the
// status was never loaded for that user and the launcher never appeared.
// Loading is idempotent: once a status has been fetched, later transitions do
// not fetch again.
watch(canUse, (value) => {
    if (!value) {
        return;
    }

    if (!statusLoaded.value) {
        void loadStatus();
    }

    if (!restored) {
        void restoreConversation();
    }
});

watch(open, (value) => {
    persistOpen();

    if (!value) {
        return;
    }

    // Refresh the status when the panel opens: a provider that was cooling down a
    // moment ago may be back, and the badge should say so.
    void loadStatus();
});
</script>

<template>
    <div v-if="canUse" class="cdh-assistant">
        <!-- The launcher. Shown to anyone who may use the assistant, whatever
             the status call answered: a status failure or a provider that is
             momentarily down must not silently remove the entry point. The real
             state is explained inside the panel. -->
        <button
            v-if="!open"
            type="button"
            class="cdh-assistant__fab"
            :aria-expanded="open"
            aria-controls="cdh-assistant-panel"
            aria-label="Abrir el asistente de IA"
            @click="toggle"
        >
            <i class="bi bi-robot" aria-hidden="true" />
        </button>

        <section
            v-if="open"
            id="cdh-assistant-panel"
            class="cdh-assistant__panel"
            :class="{ 'cdh-assistant__panel--full': fullscreen }"
            role="dialog"
            aria-modal="false"
            aria-label="Asistente de IA"
        >
            <header class="cdh-assistant__header">
                <div class="cdh-assistant__title">
                    <i class="bi bi-robot" aria-hidden="true" />
                    <div>
                        <p class="cdh-assistant__heading">Asistente IA</p>
                        <p class="cdh-assistant__status">{{ statusLabel }}</p>
                    </div>
                </div>

                <div class="cdh-assistant__actions">
                    <button type="button" class="cdh-assistant__icon-btn" title="Nueva conversación" @click="newConversation">
                        <i class="bi bi-plus-lg" aria-hidden="true" />
                        <span class="visually-hidden">Nueva conversación</span>
                    </button>
                    <button
                        type="button"
                        class="cdh-assistant__icon-btn"
                        :title="fullscreen ? 'Salir de pantalla completa' : 'Pantalla completa'"
                        @click="toggleFullscreen"
                    >
                        <i class="bi" :class="fullscreen ? 'bi-fullscreen-exit' : 'bi-arrows-fullscreen'" aria-hidden="true" />
                        <span class="visually-hidden">{{ fullscreen ? 'Salir de pantalla completa' : 'Pantalla completa' }}</span>
                    </button>
                    <RouterLink
                        class="cdh-assistant__icon-btn"
                        :to="{ name: 'asistente' }"
                        title="Abrir en la página del asistente"
                        @click="close"
                    >
                        <i class="bi bi-box-arrow-up-right" aria-hidden="true" />
                        <span class="visually-hidden">Abrir en la página del asistente</span>
                    </RouterLink>
                    <button type="button" class="cdh-assistant__icon-btn" title="Cerrar" @click="close">
                        <i class="bi bi-x-lg" aria-hidden="true" />
                        <span class="visually-hidden">Cerrar</span>
                    </button>
                </div>
            </header>

            <AppAlert
                v-if="notice"
                :variant="notice.variant"
                :message="notice.message"
                class="cdh-assistant__notice"
            />

            <div ref="scroller" class="cdh-assistant__body">
                <div v-if="messages.length === 0 && proposals.length === 0 && attachments.length === 0 && artifacts.length === 0" class="cdh-assistant__empty">
                    <i class="bi bi-chat-square-text" aria-hidden="true" />
                    <p>Pregúntame lo que necesites. Puedo consultar la cartera, los clientes y los reportes.</p>
                </div>

                <div v-if="attachments.length" class="cdh-assistant__attachments">
                    <div v-for="attachment in attachments" :key="attachment.id" class="cdh-assistant__attachment">
                        <i class="bi bi-paperclip" aria-hidden="true" />
                        <span class="cdh-assistant__attachment-name">{{ attachment.name }}</span>
                        <span v-if="!isFinishedStatus(attachment.status)" class="cdh-assistant__attachment-state">
                            {{ attachment.progress }}%
                        </span>
                        <span v-else-if="attachment.status === 'failed'" class="cdh-assistant__attachment-state cdh-assistant__attachment-state--failed">
                            No se pudo procesar
                        </span>
                        <i v-else class="bi bi-check2-circle cdh-assistant__attachment-ok" aria-hidden="true" />
                    </div>
                </div>

                <div
                    v-for="message in messages"
                    :key="message.id"
                    class="cdh-assistant__message"
                    :class="`cdh-assistant__message--${message.role}`"
                >
                    <div class="cdh-assistant__bubble">
                        <SafeMarkdown v-if="message.role === 'assistant'" :content="message.content" />
                        <span v-else class="cdh-assistant__text">{{ message.content }}</span>
                    </div>
                    <span v-if="message.role === 'assistant' && message.model" class="cdh-assistant__meta">
                        {{ message.model }}
                    </span>
                    <button
                        v-if="message.role === 'user' && message.failed"
                        type="button"
                        class="cdh-assistant__retry"
                        @click="retry(message)"
                    >
                        <i class="bi bi-arrow-clockwise" aria-hidden="true" /> Reintentar
                    </button>
                    <div v-if="message.tools && message.tools.length" class="cdh-assistant__tools">
                        <span class="cdh-assistant__tools-title">Datos consultados:</span>
                        <span v-for="tool in message.tools" :key="tool.tool" class="cdh-assistant__tool-chip">{{ tool.tool }}</span>
                    </div>
                </div>

                <div v-for="proposal in proposals" :key="proposal.id" class="cdh-assistant__proposal">
                    <p class="cdh-assistant__proposal-title">
                        <i class="bi bi-shield-exclamation" aria-hidden="true" />
                        Acción propuesta: {{ proposal.tool }}
                    </p>
                    <p class="cdh-assistant__proposal-args">{{ describeArguments(proposal.arguments) }}</p>
                    <p class="cdh-assistant__proposal-note">No se ha cambiado nada. Confirme para aplicarlo.</p>
                    <div class="cdh-assistant__proposal-actions">
                        <AppButton variant="primary" size="sm" @click="confirmProposal(proposal.id)">
                            Confirmar
                        </AppButton>
                        <AppButton variant="secondary" size="sm" @click="rejectProposal(proposal.id)">
                            Descartar
                        </AppButton>
                    </div>
                </div>

                <div v-if="artifacts.length" class="cdh-assistant__artifacts">
                    <p class="cdh-assistant__artifacts-title">Archivos generados</p>
                    <ArtifactCard v-for="artifact in artifacts" :key="artifact.id" :artifact="artifact" />
                </div>

                <div v-if="sending" class="cdh-assistant__message cdh-assistant__message--assistant">
                    <div class="cdh-assistant__bubble cdh-assistant__bubble--pending">
                        <span class="cdh-spinner" aria-hidden="true" />
                        <span class="visually-hidden">Pensando…</span>
                    </div>
                </div>
            </div>

            <AppAlert v-if="confirmation" variant="success" :message="confirmation" class="cdh-assistant__alert" />
            <AppAlert v-if="error" variant="danger" :message="error" class="cdh-assistant__alert" />

            <form class="cdh-assistant__composer" @submit.prevent="send()">
                <button
                    type="button"
                    class="cdh-assistant__attach"
                    title="Adjuntar archivo"
                    :disabled="sending"
                    @click="fileInput?.click()"
                >
                    <i class="bi bi-paperclip" aria-hidden="true" />
                    <span class="visually-hidden">Adjuntar archivo</span>
                </button>
                <input
                    ref="fileInput"
                    type="file"
                    class="visually-hidden"
                    accept=".txt,.csv,.md,.pdf,.docx,.xlsx,.json"
                    @change="onFileSelected"
                />
                <input
                    ref="input"
                    v-model="draft"
                    type="text"
                    class="form-control"
                    placeholder="Escribe una pregunta…"
                    aria-label="Pregunta para el asistente"
                    :disabled="sending"
                />
                <AppButton
                    type="submit"
                    variant="primary"
                    icon="bi-send"
                    :loading="sending"
                    :disabled="draft.trim() === ''"
                >
                    <span class="visually-hidden">Enviar</span>
                </AppButton>
            </form>
        </section>
    </div>
</template>

<style scoped>
.cdh-assistant {
    position: fixed;
    right: 1.25rem;
    bottom: 1.25rem;
    z-index: 1080;
}

.cdh-assistant__fab {
    width: 3.25rem;
    height: 3.25rem;
    border: 0;
    border-radius: 50%;
    background-color: var(--cdh-brand, #123a5e);
    color: #fff;
    font-size: 1.4rem;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
    cursor: pointer;
}

.cdh-assistant__fab:hover {
    filter: brightness(1.1);
}

.cdh-assistant__panel {
    display: flex;
    flex-direction: column;
    width: min(24rem, calc(100vw - 2rem));
    height: min(32rem, calc(100vh - 6rem));
    background: #fff;
    border-radius: 0.75rem;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.28);
    overflow: hidden;
}

.cdh-assistant__panel--full {
    width: min(64rem, calc(100vw - 2rem));
    height: calc(100vh - 2rem);
}

.cdh-assistant__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    background-color: var(--cdh-brand, #123a5e);
    color: #fff;
}

.cdh-assistant__title {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.cdh-assistant__heading {
    margin: 0;
    font-weight: 600;
}

.cdh-assistant__status {
    margin: 0;
    font-size: 0.75rem;
    opacity: 0.85;
}

.cdh-assistant__actions {
    display: flex;
    gap: 0.25rem;
}

.cdh-assistant__icon-btn {
    border: 0;
    background: transparent;
    color: #fff;
    padding: 0.35rem 0.45rem;
    border-radius: 0.35rem;
    cursor: pointer;
    text-decoration: none;
    line-height: 1;
}

.cdh-assistant__icon-btn:hover {
    background-color: rgba(255, 255, 255, 0.18);
}

.cdh-assistant__body {
    flex: 1 1 auto;
    overflow-y: auto;
    padding: 0.85rem;
    background-color: #f5f7fa;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}

.cdh-assistant__empty {
    margin: auto;
    text-align: center;
    color: var(--cdh-text-muted, #6b7280);
}

.cdh-assistant__empty i {
    font-size: 2rem;
}

.cdh-assistant__attachments {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.cdh-assistant__attachment {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.78rem;
    color: var(--cdh-text-muted, #6b7280);
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    padding: 0.35rem 0.55rem;
}

.cdh-assistant__attachment-name {
    flex: 1 1 auto;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.cdh-assistant__attachment-state--failed {
    color: #b42318;
}

.cdh-assistant__attachment-ok {
    color: #067647;
}

.cdh-assistant__message {
    display: flex;
    flex-direction: column;
    max-width: 85%;
    min-width: 0;
}

.cdh-assistant__message--user {
    align-self: flex-end;
    align-items: flex-end;
}

.cdh-assistant__message--assistant {
    align-self: flex-start;
}

.cdh-assistant__bubble {
    padding: 0.5rem 0.75rem;
    border-radius: 0.65rem;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    font-size: 0.9rem;
}

.cdh-assistant__message--user .cdh-assistant__bubble {
    background-color: var(--cdh-brand, #123a5e);
    color: #fff;
}

.cdh-assistant__bubble--pending {
    min-width: 2.5rem;
    text-align: center;
}

/* The user's own words, kept verbatim: their line breaks are meaningful and no
   Markdown they typed is interpreted. */
.cdh-assistant__text {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.cdh-assistant__artifacts {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.cdh-assistant__artifacts-title {
    margin: 0;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--cdh-text-muted, #6b7280);
}

.cdh-assistant__meta {
    font-size: 0.7rem;
    color: var(--cdh-text-muted, #6b7280);
    margin-top: 0.15rem;
}

.cdh-assistant__retry {
    margin-top: 0.25rem;
    border: 1px solid #b42318;
    background: transparent;
    color: #b42318;
    border-radius: 0.4rem;
    font-size: 0.75rem;
    padding: 0.15rem 0.5rem;
    cursor: pointer;
}

.cdh-assistant__tools {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.3rem;
    margin-top: 0.25rem;
}

.cdh-assistant__tools-title {
    font-size: 0.7rem;
    color: var(--cdh-text-muted, #6b7280);
}

.cdh-assistant__tool-chip {
    font-size: 0.68rem;
    background: #eef2ff;
    color: #3538cd;
    border-radius: 999px;
    padding: 0.05rem 0.45rem;
}

.cdh-assistant__proposal {
    border: 1px solid #f0b429;
    background: #fff8e6;
    border-radius: 0.6rem;
    padding: 0.6rem 0.75rem;
}

.cdh-assistant__proposal-title {
    margin: 0 0 0.25rem;
    font-weight: 600;
    font-size: 0.9rem;
}

.cdh-assistant__proposal-args {
    margin: 0 0 0.25rem;
    font-size: 0.8rem;
    word-break: break-word;
}

.cdh-assistant__proposal-note {
    margin: 0 0 0.5rem;
    font-size: 0.78rem;
    color: var(--cdh-text-muted, #6b7280);
}

.cdh-assistant__proposal-actions {
    display: flex;
    gap: 0.4rem;
}

.cdh-assistant__alert {
    margin: 0.5rem 0.85rem 0;
}

.cdh-assistant__notice {
    margin: 0.5rem 0.85rem 0;
}

.cdh-assistant__composer {
    display: flex;
    gap: 0.5rem;
    padding: 0.75rem;
    border-top: 1px solid #e5e7eb;
    background: #fff;
}

.cdh-assistant__attach {
    border: 1px solid #d0d5dd;
    background: #fff;
    color: var(--cdh-text-muted, #6b7280);
    border-radius: 0.5rem;
    padding: 0 0.6rem;
    cursor: pointer;
}

.cdh-assistant__attach:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

@media (max-width: 576px) {
    .cdh-assistant {
        right: 0.75rem;
        bottom: 0.75rem;
        left: 0.75rem;
    }

    .cdh-assistant__panel {
        width: 100%;
        height: min(34rem, calc(100vh - 5rem));
    }

    .cdh-assistant__panel--full {
        width: 100%;
        height: calc(100vh - 1.5rem);
    }
}

/* Small phones — 360 to 430 CSS pixels, the common Android and iPhone widths.
   The panel already fills the width; these rules buy back the few pixels the
   padding was taking so a full-width reply has room to breathe. */
@media (max-width: 430px) {
    .cdh-assistant {
        right: 0.4rem;
        bottom: 0.4rem;
        left: 0.4rem;
    }

    .cdh-assistant__header {
        padding: 0.6rem 0.7rem;
    }

    .cdh-assistant__body {
        padding: 0.6rem;
    }

    .cdh-assistant__message {
        max-width: 92%;
    }

    .cdh-assistant__composer {
        padding: 0.55rem;
    }
}
</style>
