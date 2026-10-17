<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import { ai } from '@/services/ai';
import { useAuthStore } from '@/stores/auth';

import type { AiProposal, AiStatusPayload } from '@/services/ai';

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

const open = ref(false);
const status = ref<AiStatusPayload | null>(null);
const statusLoaded = ref(false);

interface ChatMessage {
    role: 'user' | 'assistant';
    content: string;
    model: string | null;
}

const messages = ref<ChatMessage[]>([]);
const proposals = ref<AiProposal[]>([]);
const draft = ref('');
const sending = ref(false);
const error = ref<string | null>(null);
const conversationId = ref<number | null>(null);
const scroller = ref<HTMLElement | null>(null);
const input = ref<HTMLInputElement | null>(null);

/** Only shown when the deployment has the assistant on and answers that it can. */
const available = computed(() => statusLoaded.value && status.value?.ai_enabled === true);

const statusLabel = computed(() => {
    if (!status.value) {
        return 'Comprobando…';
    }

    switch (status.value.state) {
        case 'none':
            return 'Sin proveedor configurado';
        case 'unavailable':
            return 'No disponible ahora';
        case 'fallback':
            return `Respaldo${status.value.active_model ? ` · ${status.value.active_model}` : ''}`;
        default:
            return status.value.active_model ? `Operativo · ${status.value.active_model}` : 'Operativo';
    }
});

async function loadStatus(): Promise<void> {
    try {
        status.value = await ai.status();
    } catch {
        status.value = null;
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
}

function newConversation(): void {
    conversationId.value = null;
    messages.value = [];
    proposals.value = [];
    error.value = null;
    draft.value = '';
    void nextTick(() => input.value?.focus());
}

async function send(): Promise<void> {
    const question = draft.value.trim();

    if (question === '' || sending.value) {
        return;
    }

    sending.value = true;
    error.value = null;
    draft.value = '';
    messages.value = [...messages.value, { role: 'user', content: question, model: null }];
    scrollToEnd();

    try {
        const turn = await ai.ask(question, conversationId.value);

        conversationId.value = turn.conversation_id;
        proposals.value = turn.proposals;

        // Only append when the server actually produced text: an empty bubble is
        // the failure this widget must not pretend is an answer.
        if (turn.reply && turn.reply.trim() !== '') {
            messages.value = [...messages.value, { role: 'assistant', content: turn.reply, model: turn.model }];
        } else if (turn.proposals.length === 0) {
            error.value = 'El asistente no devolvió una respuesta. Intente reformular la pregunta.';
        }

        scrollToEnd();
    } catch (e) {
        // Keep the question so a retry does not cost the person their typing.
        draft.value = question;
        error.value = readError(e, 'No fue posible completar la consulta.');
    } finally {
        sending.value = false;
    }
}

async function confirmProposal(id: number): Promise<void> {
    error.value = null;

    try {
        await ai.confirmProposal(id);
        proposals.value = proposals.value.filter((proposal) => proposal.id !== id);
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
    if (event.key === 'Escape' && open.value) {
        close();
    }
}

onMounted(() => {
    if (!canUse.value) {
        return;
    }

    document.addEventListener('keydown', onKeydown);
    void loadStatus();
});

onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown);
});

watch(open, (value) => {
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
        <!-- The launcher. Hidden entirely when the assistant cannot answer, so there
             is never a button that opens an apology. -->
        <button
            v-if="!open && available"
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
                    <RouterLink
                        class="cdh-assistant__icon-btn"
                        :to="{ name: 'asistente' }"
                        title="Abrir en pantalla completa"
                        @click="close"
                    >
                        <i class="bi bi-arrows-fullscreen" aria-hidden="true" />
                        <span class="visually-hidden">Abrir en pantalla completa</span>
                    </RouterLink>
                    <button type="button" class="cdh-assistant__icon-btn" title="Cerrar" @click="close">
                        <i class="bi bi-x-lg" aria-hidden="true" />
                        <span class="visually-hidden">Cerrar</span>
                    </button>
                </div>
            </header>

            <div ref="scroller" class="cdh-assistant__body">
                <div v-if="messages.length === 0 && proposals.length === 0" class="cdh-assistant__empty">
                    <i class="bi bi-chat-square-text" aria-hidden="true" />
                    <p>Pregúntame lo que necesites. Puedo consultar la cartera, los clientes y los reportes.</p>
                </div>

                <div
                    v-for="(message, index) in messages"
                    :key="index"
                    class="cdh-assistant__message"
                    :class="`cdh-assistant__message--${message.role}`"
                >
                    <div class="cdh-assistant__bubble">
                        <span style="white-space: pre-wrap;">{{ message.content }}</span>
                    </div>
                    <span v-if="message.role === 'assistant' && message.model" class="cdh-assistant__meta">
                        {{ message.model }}
                    </span>
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

                <div v-if="sending" class="cdh-assistant__message cdh-assistant__message--assistant">
                    <div class="cdh-assistant__bubble cdh-assistant__bubble--pending">
                        <span class="cdh-spinner" aria-hidden="true" />
                        <span class="visually-hidden">Pensando…</span>
                    </div>
                </div>
            </div>

            <AppAlert v-if="error" variant="danger" :message="error" class="cdh-assistant__alert" />

            <form class="cdh-assistant__composer" @submit.prevent="send">
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

.cdh-assistant__message {
    display: flex;
    flex-direction: column;
    max-width: 85%;
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

.cdh-assistant__meta {
    font-size: 0.7rem;
    color: var(--cdh-text-muted, #6b7280);
    margin-top: 0.15rem;
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

.cdh-assistant__composer {
    display: flex;
    gap: 0.5rem;
    padding: 0.75rem;
    border-top: 1px solid #e5e7eb;
    background: #fff;
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
}
</style>
