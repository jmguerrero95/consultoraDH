<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';

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
import { ai } from '@/services/ai';

import type { AiArtifact, AiConversationSummary, AiMessagePayload, AiProposal, AiStatusPayload } from '@/services/ai';
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
const proposals = ref<AiProposal[]>([]);
/** Files this conversation has produced, shown in the thread that asked for them. */
const artifacts = ref<AiArtifact[]>([]);
const sources = ref<{ title: string; url: string; domain: string }[]>([]);
const draft = ref('');
const sending = ref(false);
const loading = ref(true);
const status = ref<AiStatusPayload | null>(null);
const error = ref<string | null>(null);
/** The server's own account of a confirmed write, shown in green. */
const confirmation = ref<string | null>(null);

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

/** An unconfigured provider is reported plainly rather than as a failure. */
const availability = computed(() => {
    if (!status.value) {
        return 'Comprobando el servicio…';
    }

    if (!status.value.ai_enabled) {
        return 'Asistente desactivado en este entorno.';
    }

    // BUG-017: say which provider is answering *now*, and name the model, rather
    // than reporting whether a slot was ever configured.
    switch (status.value.state) {
        case 'none':
            return 'No hay ningún proveedor de IA configurado.';
        case 'unavailable':
            return 'Los proveedores de IA no están disponibles en este momento; se reintentará automáticamente.';
        case 'quota_limited':
            return 'El proveedor de IA alcanzó su límite de uso. Puede reintentar en unos minutos.';
        case 'fallback':
            return `Operativo con el proveedor de respaldo${model.value !== '—' ? ` (${model.value})` : ''}.`;
        default:
            return `Operativo${model.value !== '—' ? ` con ${model.value}` : ''}.`;
    }
});

onMounted(async () => {
    await Promise.all([loadStatus(), loadConversations()]);
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

async function selectConversation(id: number): Promise<void> {
    selectedId.value = id;
    proposals.value = [];
    sources.value = [];
    artifacts.value = [];
    error.value = null;
    confirmation.value = null;

    // Dictation belongs to the turn being abandoned, not to the page. Recording
    // continues in the background otherwise, and the next thing captured is
    // typed into a conversation the person never chose.
    dictation.abandon();

    try {
        const payload = await ai.history(id);
        messages.value = payload.messages;
        artifacts.value = payload.artifacts ?? [];
    } catch (e) {
        messages.value = [];
        artifacts.value = [];
        error.value = readError(e, 'No fue posible cargar la conversación.');
    }
}

function startNewConversation(): void {
    selectedId.value = null;
    messages.value = [];
    proposals.value = [];
    sources.value = [];
    artifacts.value = [];
    error.value = null;
    confirmation.value = null;

    dictation.abandon();
}

/**
 * Ask. The backend creates the conversation when none is selected, so the id
 * comes back on the first turn rather than from a separate create call.
 */
async function send(): Promise<void> {
    const question = draft.value.trim();

    if (question === '' || sending.value) {
        return;
    }

    sending.value = true;
    error.value = null;
    confirmation.value = null;
    draft.value = '';

    // Rendered immediately so the question stays on screen while the model
    // works; the reload below replaces it with the persisted copy.
    messages.value = [...messages.value, { role: 'user', content: question, provider: null, model: null, created_at: null }];

    try {
        const turn = await ai.ask(question, selectedId.value);

        selectedId.value = turn.conversation_id;
        proposals.value = turn.proposals;
        collectSources(turn.tools);
        mergeArtifacts(turn.artifacts ?? []);

        if (turn.reply) {
            messages.value = [
                ...messages.value,
                { role: 'assistant', content: turn.reply, provider: turn.provider, model: turn.model, created_at: null },
            ];
        }

        await loadConversations();
    } catch (e) {
        // Keep the question in the draft: dropping it loses what was typed on a
        // failure the user can retry, which is the moment it matters most.
        draft.value = question;
        error.value = readError(e, 'No fue posible completar la consulta.');
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
        proposals.value = proposals.value.filter((proposal) => proposal.id !== id);
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
        proposals.value = proposals.value.filter((proposal) => proposal.id !== id);
    } catch (e) {
        error.value = readError(e, 'No fue posible rechazar la propuesta.');
    }
}

async function refreshIfSelected(): Promise<void> {
    if (selectedId.value !== null) {
        const payload = await ai.history(selectedId.value);
        messages.value = payload.messages;
        artifacts.value = payload.artifacts ?? [];
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
            Above the transcript and below the error, so it is the first thing
            read after the page opens and never covers work already on screen.
        -->
        <!--
            Background work, and what became of it. Shown here because this is the
            screen people come back to after closing the app mid-task.
        -->
        <TaskPanel class="mb-3" :controller="tasks" />

        <SuggestionHint
            v-if="hint.hasSuggestion.value && hint.suggestion.value"
            class="mb-3"
            :suggestion="hint.suggestion.value"
            :busy="hint.busy.value"
            @accept="acceptHint"
            @dismiss="dismissHint"
        />

        <div class="cdh-ai__body">
            <aside class="cdh-ai__sidebar" aria-label="Conversaciones">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="cdh-ai__sidebar-title">Conversaciones</h2>
                    <AppButton variant="link" size="sm" @click="startNewConversation">Nueva</AppButton>
                </div>

                <AppEmptyState v-if="conversations.length === 0" title="Sin conversaciones" icon="bi-chat-square-text" />

                <ul v-else class="cdh-ai__conversations">
                    <li v-for="conversation in conversations" :key="conversation.id">
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
                    </li>
                </ul>
            </aside>

            <section class="cdh-ai__main" aria-label="Conversación">
                <div class="cdh-ai__transcript" role="log" aria-live="polite">
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

                <!-- The files this thread produced. A download link here is the
                     difference between an answer and a proposal to answer. -->
                <div v-if="artifacts.length > 0" class="cdh-ai__artifacts" data-testid="artifacts">
                    <h3 class="cdh-ai__artifacts-title">Archivos</h3>
                    <ArtifactCard v-for="artifact in artifacts" :key="artifact.id" :artifact="artifact" />
                </div>

                <div v-if="sources.length > 0" class="cdh-ai__sources">
                    <h3 class="cdh-ai__sources-title">Fuentes</h3>
                    <ul>
                        <li v-for="source in sources" :key="source.url">
                            <a :href="source.url" target="_blank" rel="noopener noreferrer">{{ source.title }}</a>
                            <span v-if="source.domain" class="cdh-ai__source-domain">{{ source.domain }}</span>
                        </li>
                    </ul>
                </div>

                <div v-if="proposals.length > 0" class="cdh-ai__proposals">
                    <h3 class="cdh-ai__proposals-title">Acciones propuestas</h3>
                    <p class="cdh-ai__proposals-note">Nada se ejecuta hasta que lo confirmes.</p>

                    <div v-for="proposal in proposals" :key="proposal.id" class="cdh-ai__proposal">
                        <p class="cdh-ai__proposal-tool">{{ proposal.tool }}</p>
                        <pre class="cdh-ai__proposal-args">{{ JSON.stringify(proposal.arguments, null, 2) }}</pre>
                        <div class="d-flex gap-2">
                            <AppButton size="sm" @click="confirmProposal(proposal.id)">Confirmar</AppButton>
                            <AppButton size="sm" variant="secondary" @click="rejectProposal(proposal.id)">Rechazar</AppButton>
                        </div>
                    </div>
                </div>

                <form class="cdh-ai__composer" @submit.prevent="send">
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
.cdh-ai__header {
    margin-bottom: var(--cdh-space-4);
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

.cdh-ai__pending {
    margin: 0;
    color: var(--cdh-text-muted);
    font-size: 0.875rem;
}

.cdh-ai__sources,
.cdh-ai__artifacts,
.cdh-ai__proposals {
    border: 1px solid var(--cdh-border);
    border-radius: var(--cdh-radius-lg, 0.5rem);
    padding: var(--cdh-space-3);
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

    .cdh-ai__sidebar {
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
