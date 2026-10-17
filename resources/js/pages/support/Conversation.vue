<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import ClientPicker from '@/components/clients/ClientPicker.vue';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { api } from '@/services/api';
import { useRealtime } from '@/composables/useRealtime';
import { useAuthStore } from '@/stores/auth';

/**
 * A support conversation, and the form that opens one.
 *
 * ## Why create mode is on this screen
 *
 * `support.create` and `support.show` have always pointed at this component, but it only
 * ever knew how to *read* a conversation: it took the id from the route and asked the
 * server for it. On the create route there is no id, so it asked for conversation `NaN`,
 * rendered "Error al cargar la conversación", and offered no way to open one — and the
 * endpoint it would have posted to did not exist either. Both halves are fixed: the
 * server has a `store`, and this screen renders the form when there is no id.
 */

interface Conversation {
  id: number;
  subject: string;
  status: string;
  status_label?: string;
  priority: string;
  priority_label?: string;
  origin_channel: string;
  client?: { id: number; first_names?: string; last_names?: string; document_number?: string; document_label?: string } | null;
  queue?: { id: number; name: string } | null;
  assignee?: { id: number; name: string } | null;
  last_message_at: string | null;
  unread_count?: number;
  messages?: Message[];
}

interface Message {
  id: number;
  body_text: string | null;
  sender_kind: 'staff' | 'client' | 'external' | 'system';
  message_kind: 'message' | 'note' | 'system';
  channel: string;
  client_visible: boolean;
  created_at: string;
  author?: { id: number; name: string } | null;
  attachments?: Attachment[];
}

interface Attachment {
  id: number;
  kind: 'file' | 'audio' | string;
  original_name: string;
  mime_type: string | null;
  size_bytes: number;
  stored_path: string;
  sha256: string;
}

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const realtime = useRealtime();

const conversation = ref<Conversation | null>(null);
const messages = ref<Message[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const sending = ref(false);
const sendingNote = ref(false);
const replyText = ref('');
const noteText = ref('');
const showNoteForm = ref(false);
const newPriority = ref('');
const newQueueId = ref<number | null>(null);
const newAssigneeId = ref<number | null>(null);
const actionError = ref<string | null>(null);

const audioRecorder = ref<MediaRecorder | null>(null);
const audioStream = ref<MediaStream | null>(null);
const audioChunks = ref<Blob[]>([]);
const isRecording = ref(false);
const audioDuration = ref(0);
const recordingTimer = ref<number | null>(null);
const uploadingAttachment = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);

const queues = ref<{ id: number; name: string }[]>([]);
const staffUsers = ref<{ id: number; name: string }[]>([]);
const hasMoreMessages = ref(false);

/** Create-mode state. */
const isCreateMode = computed(() => conversationId.value === null);
const newClientId = ref<number | null>(null);
const newSubject = ref('');
const newBody = ref('');
const newQueue = ref<number | null>(null);
const creating = ref(false);
const createError = ref<string | null>(null);

/**
 * The conversation id, or `null` when this is the create screen.
 *
 * `parseInt` of a missing param is `NaN`, and `NaN` used to travel: into the GET, into the
 * realtime channel name (`support.conversation.NaN`, which the server answered with a 500
 * from `/broadcasting/auth`) and into every button URL. One computed decides it, once.
 */
const conversationId = computed<number | null>(() => {
  const raw = route.params.id;

  if (raw === undefined || raw === null || raw === '') {
    return null;
  }

  const parsed = Number.parseInt(String(raw), 10);

  return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
});

const priorityOptions = [
  { value: 'normal', label: 'Normal' },
  { value: 'high', label: 'Alta' },
  { value: 'urgent', label: 'Urgente' },
];

const canReply = computed(() => auth.can('support.reply'));
const canAssign = computed(() => auth.can('support.assign'));
const canResolve = computed(() => auth.can('support.resolve'));

const STATUS_LABELS: Record<string, string> = {
  open: 'Abierta',
  waiting_staff: 'Esperando staff',
  waiting_client: 'Esperando cliente',
  resolved: 'Resuelta',
  closed: 'Cerrada',
};

const PRIORITY_LABELS: Record<string, string> = {
  normal: 'Normal',
  high: 'Alta',
  urgent: 'Urgente',
};

/**
 * A status never renders as an empty badge.
 *
 * `labels[status] || status` produced `''` whenever the value was missing or unknown, and an
 * empty pill reads as a layout bug rather than as "the server did not send this".
 */
const statusLabel = (status: string | undefined | null): string =>
  (status ? STATUS_LABELS[status] ?? status : '') || 'Sin estado';

const priorityLabel = (priority: string | undefined | null): string =>
  (priority ? PRIORITY_LABELS[priority] ?? priority : '') || 'Sin prioridad';

/**
 * A timestamp that never renders as `Invalid Date`.
 *
 * `new Date(undefined)` is `Invalid Date`, every subtraction from it is `NaN`, every
 * comparison is false, and the final `toLocaleDateString` prints "Invalid Date" into the
 * transcript. An unusable stamp falls back to a neutral phrase.
 */
function formatRelativeTime(value: string | null | undefined): string {
  if (!value) {
    return 'Fecha desconocida';
  }

  const date = new Date(value);
  const timestamp = date.getTime();

  if (Number.isNaN(timestamp)) {
    return 'Fecha desconocida';
  }

  const diffMins = Math.floor((Date.now() - timestamp) / 60000);

  if (diffMins < 1) return 'Ahora';
  if (diffMins < 60) return `hace ${diffMins} min`;
  if (diffMins < 1440) return `hace ${Math.floor(diffMins / 60)} h`;
  if (diffMins < 10080) return `hace ${Math.floor(diffMins / 1440)} d`;

  return date.toLocaleString('es-CO', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

const isInternalNote = (msg: Message) => msg.message_kind === 'note';
const isStaffMessage = (msg: Message) => msg.sender_kind === 'staff';

/**
 * Who wrote a message, in words.
 *
 * The name used to come from `author.name` alone, which is null for anything the client or
 * the e-mail ingress wrote, so half the thread was attributed to nobody. The role is now
 * the fallback and the role is always stated.
 */
function authorName(msg: Message): string {
  if (msg.author?.name) {
    return msg.author.name;
  }

  return {
    staff: 'Equipo',
    client: 'Cliente',
    external: 'Remitente externo',
    system: 'Sistema',
  }[msg.sender_kind] ?? 'Autor desconocido';
}

function authorRole(msg: Message): string {
  if (isInternalNote(msg)) return 'Nota interna';
  if (msg.sender_kind === 'system') return 'Sistema';
  if (msg.sender_kind === 'external') return 'Externo';
  if (msg.sender_kind === 'staff') return 'Equipo';
  return 'Cliente';
}

const formatBytes = (bytes: number | null | undefined) => {
  const value = typeof bytes === 'number' && Number.isFinite(bytes) ? bytes : 0;

  if (value < 1024) return `${value} B`;
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;

  return `${(value / (1024 * 1024)).toFixed(1)} MB`;
};

/**
 * What an attachment actually is.
 *
 * The icon was chosen from `kind`, and audio uploaded before the kind was recorded came back
 * as `file`, so a voice note rendered as a generic file. The detected MIME type and the
 * stored extension are the truth; `kind` is only a hint.
 */
function attachmentKind(att: Attachment): 'audio' | 'image' | 'pdf' | 'document' | 'file' {
  const mime = att.mime_type ?? '';
  const name = (att.original_name ?? '').toLowerCase();

  if (att.kind === 'audio' || mime.startsWith('audio/') || /\.(webm|ogg|mp3|m4a|wav)$/.test(name)) {
    return 'audio';
  }

  if (mime.startsWith('image/') || /\.(png|jpe?g|gif|webp)$/.test(name)) {
    return 'image';
  }

  if (mime === 'application/pdf' || name.endsWith('.pdf')) {
    return 'pdf';
  }

  if (/\.(docx?|xlsx?|csv)$/.test(name)) {
    return 'document';
  }

  return 'file';
}

const ATTACHMENT_ICONS: Record<string, string> = {
  audio: 'bi-file-music',
  image: 'bi-file-image',
  pdf: 'bi-file-pdf',
  document: 'bi-file-earmark-text',
  file: 'bi-file-earmark',
};

/**
 * The real download URL.
 *
 * The control was a `<button>` carrying `:href` and `download`; a button has neither
 * attribute, so pressing it did nothing at all. It is an anchor now, pointing at the
 * authorised streaming endpoint, and the same URL is what the audio player loads — the
 * player used to be handed `stored_path`, the private disk path, and asked the browser to
 * fetch it as a URL.
 */
function attachmentUrl(att: Attachment): string {
  return `/api/support/conversations/${conversationId.value}/attachments/${att.id}`;
}

function playAudio(att: Attachment): void {
  const audio = new Audio(attachmentUrl(att));
  void audio.play().catch(() => {
    actionError.value = 'No se pudo reproducir el audio.';
  });
}

/** Ascending by time, ties broken by id, so the transcript reads top to bottom. */
function orderMessages(list: Message[]): Message[] {
  return [...list].sort((a, b) => {
    const left = Date.parse(a.created_at);
    const right = Date.parse(b.created_at);
    const l = Number.isNaN(left) ? Number.MAX_SAFE_INTEGER : left;
    const r = Number.isNaN(right) ? Number.MAX_SAFE_INTEGER : right;

    return l === r ? a.id - b.id : l - r;
  });
}

async function loadConversation() {
  const id = conversationId.value;

  if (id === null) {
    // Create mode: nothing to load.
    return;
  }

  loading.value = true;
  error.value = null;

  try {
    const response = (await api.support.conversation(id, { per_page: 50 })) as Conversation;
    conversation.value = response;
    const loaded = response.messages ?? [];
    messages.value = orderMessages(loaded);
    // The server sends at most `per_page`; a full page means there is probably more history.
    hasMoreMessages.value = loaded.length >= 50;
    await loadMetadata();
    await markRead();
    await nextTick();
    scrollToBottom();
  } catch (e) {
    error.value = 'No se pudo cargar la conversación. Intente de nuevo.';
    console.error(e);
  } finally {
    loading.value = false;
  }
}

async function loadMetadata() {
  try {
    const [queuesRes, staffRes] = await Promise.all([api.support.queues(), api.support.agents()]);
    queues.value = queuesRes.data;
    staffUsers.value = (staffRes.data ?? []).map((u) => ({ id: u.id, name: u.name }));
  } catch (e) {
    // The conversation is readable without them; the dropdowns simply stay empty and say so.
    console.error('Error loading support metadata:', e);
  }
}

async function markRead(): Promise<void> {
  const id = conversationId.value;

  if (id === null || messages.value.length === 0) {
    return;
  }

  const newest = messages.value[messages.value.length - 1];

  try {
    await api.support.markRead(id, newest.id);
  } catch {
    // Read state is cosmetic: failing to record it must not fail the screen.
  }
}

async function loadMoreMessages() {
  const id = conversationId.value;

  if (id === null || !hasMoreMessages.value || messages.value.length === 0) return;

  const oldest = messages.value[0];

  try {
    const response = (await api.support.loadMoreMessages(id, oldest.id, 50)) as Conversation;
    const older = response.messages ?? [];
    const merged = orderMessages([...older, ...messages.value]);
    const seen = new Set<number>();

    messages.value = merged.filter((m) => (seen.has(m.id) ? false : (seen.add(m.id), true)));
    hasMoreMessages.value = older.length >= 50;
  } catch (e) {
    actionError.value = 'No se pudo cargar el historial anterior.';
    console.error('Error loading more messages:', e);
  }
}

async function sendMessage() {
  const id = conversationId.value;

  if (id === null || !replyText.value.trim() || sending.value) return;

  sending.value = true;
  actionError.value = null;

  try {
    const newMessage = (await api.support.sendMessage(id, { body_text: replyText.value.trim() })) as Message;
    appendIncoming(newMessage);
    replyText.value = '';
  } catch (e) {
    actionError.value = 'No se pudo enviar el mensaje. Intente de nuevo.';
    console.error('Error sending message:', e);
  } finally {
    sending.value = false;
  }
}

async function sendNote() {
  const id = conversationId.value;

  if (id === null || !noteText.value.trim() || sendingNote.value) return;

  sendingNote.value = true;
  actionError.value = null;

  try {
    const newNote = (await api.support.sendNote(id, { body_text: noteText.value.trim() })) as Message;
    appendIncoming(newNote);
    noteText.value = '';
    showNoteForm.value = false;
  } catch (e) {
    actionError.value = 'No se pudo guardar la nota interna.';
    console.error('Error sending note:', e);
  } finally {
    sendingNote.value = false;
  }
}

async function changePriority(priority: string) {
  const id = conversationId.value;

  if (id === null || !priority) return;

  newPriority.value = priority;
  actionError.value = null;

  try {
    await api.support.changePriority(id, priority);
    if (conversation.value) conversation.value.priority = priority;
  } catch (e) {
    actionError.value = 'No se pudo cambiar la prioridad.';
    console.error('Error changing priority:', e);
  } finally {
    newPriority.value = '';
  }
}

async function changeQueue(queueId: number) {
  const id = conversationId.value;

  if (id === null || !queueId) return;

  actionError.value = null;

  try {
    const updated = (await api.support.changeQueue(id, { queue_id: queueId })) as unknown as Conversation;

    if (conversation.value) {
      conversation.value.queue = updated.queue ?? conversation.value.queue;
      conversation.value.assignee = updated.assignee ?? null;
    }
  } catch (e) {
    actionError.value = 'No se pudo cambiar la cola.';
    console.error('Error changing queue:', e);
  } finally {
    newQueueId.value = null;
  }
}

async function assignConversation(userId: number | null) {
  const id = conversationId.value;

  if (id === null) return;

  actionError.value = null;

  try {
    const updated = (await api.support.assignConversation(id, userId)) as unknown as Conversation;

    if (conversation.value) {
      conversation.value.assignee = updated.assignee ?? null;
    }

    if (userId !== null) {
      // A name the operator has to be able to confirm after a reload.
      conversation.value!.assignee = updated.assignee ?? staffUsers.value.find((u) => u.id === userId) ?? null;
    }
  } catch (e) {
    actionError.value = 'No se pudo asignar la conversación.';
    console.error('Error assigning conversation:', e);
  } finally {
    newAssigneeId.value = null;
  }
}

async function resolveConversation() {
  const id = conversationId.value;

  if (id === null) return;

  actionError.value = null;

  try {
    await api.support.resolveConversation(id);
    if (conversation.value) conversation.value.status = 'resolved';
  } catch (e) {
    actionError.value = 'No se pudo resolver la conversación.';
    console.error('Error resolving conversation:', e);
  }
}

async function closeConversation() {
  const id = conversationId.value;

  if (id === null) return;

  actionError.value = null;

  try {
    await api.support.closeConversation(id);
    if (conversation.value) conversation.value.status = 'closed';
  } catch (e) {
    actionError.value = 'No se pudo cerrar la conversación.';
    console.error('Error closing conversation:', e);
  }
}

async function reopenConversation() {
  const id = conversationId.value;

  if (id === null) return;

  actionError.value = null;

  try {
    await api.support.reopenConversation(id);
    await loadConversation();
  } catch (e) {
    actionError.value = 'No se pudo reabrir la conversación.';
    console.error('Error reopening conversation:', e);
  }
}

/**
 * Recording, stopping, and letting the microphone go.
 *
 * Three things were wrong. The button was `:disabled="isRecording"`, so recording could
 * never be stopped from the interface; the `MediaStream` was never closed, so the browser's
 * recording indicator stayed on after the clip was sent; and a cancelled recording still
 * uploaded. The stream is now held so it can be released, `stop` and `cancel` are separate,
 * and every path releases the tracks.
 */
async function startRecording(): Promise<void> {
  if (isRecording.value) return;

  actionError.value = null;

  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    audioStream.value = stream;

    const recorder = new MediaRecorder(stream);
    audioRecorder.value = recorder;
    audioChunks.value = [];

    recorder.ondataavailable = (event) => {
      if (event.data.size > 0) audioChunks.value.push(event.data);
    };

    recorder.onstop = () => {
      const blob = new Blob(audioChunks.value, { type: 'audio/webm' });
      releaseMicrophone();

      if (blob.size > 0 && !cancelledRecording) {
        void uploadAudio(blob);
      }

      cancelledRecording = false;
    };

    recorder.start();
    isRecording.value = true;
    audioDuration.value = 0;

    recordingTimer.value = window.setInterval(() => {
      audioDuration.value += 1;
    }, 1000);
  } catch (e) {
    releaseMicrophone();
    actionError.value = 'No se pudo acceder al micrófono. Revise los permisos del navegador.';
    console.error('Error accessing microphone:', e);
  }
}

let cancelledRecording = false;

function stopRecording(): void {
  if (audioRecorder.value && audioRecorder.value.state !== 'inactive') {
    audioRecorder.value.stop();
  }

  isRecording.value = false;

  if (recordingTimer.value !== null) {
    clearInterval(recordingTimer.value);
    recordingTimer.value = null;
  }
}

function cancelRecording(): void {
  cancelledRecording = true;
  stopRecording();
}

function releaseMicrophone(): void {
  audioStream.value?.getTracks().forEach((track) => track.stop());
  audioStream.value = null;
  audioRecorder.value = null;
  audioChunks.value = [];
  isRecording.value = false;

  if (recordingTimer.value !== null) {
    clearInterval(recordingTimer.value);
    recordingTimer.value = null;
  }
}

async function uploadAudio(blob: Blob): Promise<void> {
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const file = new File([blob], `nota-de-voz-${stamp}.webm`, { type: 'audio/webm' });
  await uploadAttachment(file, 'Mensaje de voz');
}

async function uploadAttachment(file: File, caption = 'Adjunto'): Promise<void> {
  const id = conversationId.value;

  if (id === null) return;

  uploadingAttachment.value = true;
  actionError.value = null;

  try {
    const attachment = (await api.support.uploadAttachment(id, file)) as Attachment;
    const sent = (await api.support.sendMessageWithAttachment(id, {
      body_text: caption,
      attachment_ids: [attachment.id],
    })) as Message;
    appendIncoming(sent);
  } catch (e) {
    actionError.value = 'No se pudo adjuntar el archivo.';
    console.error('Error uploading attachment:', e);
  } finally {
    uploadingAttachment.value = false;
  }
}

function triggerFileInput(): void {
  fileInput.value?.click();
}

function handleFileUpload(event: Event): void {
  const input = event.target as HTMLInputElement;

  if (input.files && input.files[0]) {
    void uploadAttachment(input.files[0]);
    input.value = '';
  }
}

function scrollToBottom(): void {
  const container = document.querySelector('.messages-container');

  if (container) {
    container.scrollTop = container.scrollHeight;
  }
}

/**
 * Append a message that arrived over the socket or from a POST, keeping the transcript in
 * order and free of duplicates. Keyed by id, because the sender's own POST returns the row
 * that the broadcast then delivers again.
 */
function appendIncoming(message: Message | null | undefined): void {
  if (!message || message.id === undefined || message.id === null) return;

  if (messages.value.some((existing) => existing.id === message.id)) return;

  messages.value = orderMessages([...messages.value, message]);
  void nextTick(() => scrollToBottom());
}

/* --- Create mode --------------------------------------------------------- */

async function createConversation(): Promise<void> {
  if (creating.value) return;

  createError.value = null;

  if (newClientId.value === null) {
    createError.value = 'Seleccione el cliente de la conversación.';
    return;
  }

  if (newSubject.value.trim() === '' || newBody.value.trim() === '') {
    createError.value = 'El asunto y el mensaje son obligatorios.';
    return;
  }

  creating.value = true;

  try {
    const created = (await api.support.createConversation({
      client_id: newClientId.value,
      subject: newSubject.value.trim(),
      body_text: newBody.value.trim(),
      queue_id: newQueue.value,
    })) as unknown as Conversation;

    await router.replace({ name: 'support.show', params: { id: created.id } });
  } catch (e) {
    createError.value = 'No se pudo crear la conversación. Revise los datos e intente de nuevo.';
    console.error('Error creating conversation:', e);
  } finally {
    creating.value = false;
  }
}

onMounted(() => {
  void loadConversation();

  // No id means no conversation, so no channel: subscribing would ask the server to
  // authorise `support.conversation.NaN`, which is what produced the 500 on
  // `/broadcasting/auth`.
  const id = conversationId.value;

  if (id === null) {
    return;
  }

  const unsubscribe = realtime.subscribeToConversation(id, {
    onMessage: (message) => appendIncoming(message),
    onConversationUpdate: (data) => {
      if (conversation.value && data.conversation) {
        conversation.value = { ...conversation.value, ...data.conversation };
      }
    },
  });

  const unsubscribeStaff = realtime.subscribeToStaffConversation(id, {
    onNote: (message) => appendIncoming(message),
  });

  const container = document.querySelector('.messages-container');

  if (container) {
    container.addEventListener('scroll', () => {
      if (container.scrollTop === 0 && hasMoreMessages.value && !loading.value) {
        void loadMoreMessages();
      }
    });
  }

  cleanupRealtime = () => {
    unsubscribe();
    unsubscribeStaff();
  };
});

let cleanupRealtime: (() => void) | null = null;

watch(messages, () => {
  void nextTick(() => scrollToBottom());
}, { deep: true });

onUnmounted(() => {
  cleanupRealtime?.();
  releaseMicrophone();
});
</script>

<template>
    <div class="support-conversation h-100 d-flex flex-column">
        <!-- Create mode: reached from the Soporte inbox, with no conversation yet. -->
        <div v-if="isCreateMode" class="p-4" style="max-width: 720px;">
            <h1 class="h5 mb-3">Nueva conversación</h1>

            <AppAlert v-if="createError" variant="danger" :message="createError" class="mb-3" />

            <div class="mb-3">
                <ClientPicker v-model="newClientId" label="Cliente" />
            </div>

            <div class="mb-3">
                <label class="form-label" for="support-new-subject">Asunto</label>
                <input
                    id="support-new-subject"
                    v-model="newSubject"
                    class="form-control"
                    type="text"
                    maxlength="255"
                />
            </div>

            <div class="mb-3">
                <label class="form-label" for="support-new-queue">Cola</label>
                <select id="support-new-queue" v-model="newQueue" class="form-select">
                    <option :value="null">Cola por defecto</option>
                    <option v-for="q in queues" :key="q.id" :value="q.id">{{ q.name }}</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label" for="support-new-body">Mensaje</label>
                <textarea
                    id="support-new-body"
                    v-model="newBody"
                    class="form-control"
                    rows="5"
                />
            </div>

            <div class="d-flex gap-2">
                <AppButton :loading="creating" @click="createConversation">Crear conversación</AppButton>
                <AppButton variant="secondary" :to="{ name: 'support' }">Cancelar</AppButton>
            </div>
        </div>

        <div v-else-if="loading" class="d-flex justify-content-center align-items-center flex-grow-1">
            <AppLoading label="Cargando la conversación" />
        </div>

        <div v-else-if="error" class="p-4">
            <AppAlert variant="danger" :message="error" />
            <AppButton class="mt-3" variant="secondary" @click="loadConversation">Reintentar</AppButton>
        </div>

        <div v-else class="h-100 d-flex flex-column">
            <!-- Header -->
            <div class="conversation-header border-bottom px-3 py-3 bg-white">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h2 class="h5 mb-1">{{ conversation?.subject || 'Conversación' }}</h2>

                        <!-- MEJ-009: who the conversation is about, by name and document. -->
                        <p v-if="conversation?.client" class="mb-1 text-muted small">
                            {{ conversation.client.first_names }} {{ conversation.client.last_names }}
                            <span v-if="conversation.client.document_label || conversation.client.document_number">
                                · {{ conversation.client.document_label || conversation.client.document_number }}
                            </span>
                        </p>

                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="badge bg-secondary text-white">
                                {{ statusLabel(conversation?.status) }}
                            </span>
                            <span class="badge bg-secondary text-white">
                                {{ priorityLabel(conversation?.priority) }}
                            </span>
                            <span class="badge bg-light text-dark border">
                                {{ conversation?.queue?.name || 'Sin cola' }}
                            </span>
                            <span v-if="conversation?.assignee" class="badge bg-info text-white">
                                {{ conversation.assignee.name }}
                            </span>
                            <span v-else class="badge bg-light text-dark border">Sin asignar</span>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <AppButton
                            v-if="canResolve && conversation?.status !== 'closed' && conversation?.status !== 'resolved'"
                            variant="secondary"
                            size="sm"
                            @click="resolveConversation"
                        >
                            Resolver
                        </AppButton>
                        <AppButton
                            v-if="canResolve && conversation?.status === 'resolved'"
                            variant="secondary"
                            size="sm"
                            @click="reopenConversation"
                        >
                            Reabrir
                        </AppButton>
                        <AppButton
                            v-if="canResolve && conversation?.status !== 'closed'"
                            variant="danger"
                            size="sm"
                            @click="closeConversation"
                        >
                            Cerrar
                        </AppButton>
                    </div>
                </div>

                <AppAlert v-if="actionError" variant="danger" :message="actionError" class="mt-2" />

                <div class="d-flex gap-2 mt-2 flex-wrap">
                    <div v-if="canAssign" class="btn-group" role="group" aria-label="Prioridad">
                        <button
                            v-for="p in priorityOptions"
                            :key="p.value"
                            type="button"
                            class="btn btn-sm"
                            :class="conversation?.priority === p.value ? 'btn-primary' : 'btn-outline-secondary'"
                            :aria-pressed="conversation?.priority === p.value"
                            @click="changePriority(p.value)"
                        >
                            {{ p.label }}
                        </button>
                    </div>

                    <div v-if="canAssign" class="dropdown">
                        <button
                            class="btn btn-outline-secondary btn-sm dropdown-toggle"
                            type="button"
                            data-bs-toggle="dropdown"
                        >
                            Cola: {{ conversation?.queue?.name || 'Sin cola' }}
                        </button>
                        <ul class="dropdown-menu">
                            <li v-for="q in queues" :key="q.id">
                                <button
                                    class="dropdown-item"
                                    :class="{ active: conversation?.queue?.id === q.id }"
                                    @click="changeQueue(q.id)"
                                >
                                    {{ q.name }}
                                </button>
                            </li>
                            <li v-if="queues.length === 0" class="dropdown-item-text text-muted">
                                No hay colas disponibles
                            </li>
                        </ul>
                    </div>

                    <div v-if="canAssign" class="dropdown">
                        <button
                            class="btn btn-outline-secondary btn-sm dropdown-toggle"
                            type="button"
                            data-bs-toggle="dropdown"
                        >
                            Asignar: {{ conversation?.assignee?.name || 'Sin asignar' }}
                        </button>
                        <ul class="dropdown-menu">
                            <li>
                                <button
                                    class="dropdown-item"
                                    :class="{ active: !conversation?.assignee }"
                                    @click="assignConversation(null)"
                                >
                                    Sin asignar
                                </button>
                            </li>
                            <li v-for="u in staffUsers" :key="u.id">
                                <button
                                    class="dropdown-item"
                                    :class="{ active: conversation?.assignee?.id === u.id }"
                                    @click="assignConversation(u.id)"
                                >
                                    {{ u.name }}
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <div class="flex-grow-1 overflow-auto messages-container p-3" style="background-color: #f5f7fa;">
                <div v-if="messages.length === 0" class="text-center text-muted py-5">
                    <i class="bi bi-chat display-1" aria-hidden="true" />
                    <p class="mt-3">No hay mensajes en esta conversación</p>
                </div>

                <div v-else>
                    <button
                        v-if="hasMoreMessages"
                        type="button"
                        class="btn btn-sm btn-outline-secondary d-block mx-auto mb-3"
                        @click="loadMoreMessages"
                    >
                        Cargar mensajes anteriores
                    </button>

                    <div
                        v-for="msg in messages"
                        :key="msg.id"
                        class="message-item mb-3"
                        :class="{ 'text-end': isStaffMessage(msg), 'ms-auto': isStaffMessage(msg), 'me-auto': !isStaffMessage(msg) }"
                        style="max-width: 80%;"
                    >
                        <div class="d-flex gap-2" :class="{ 'flex-row-reverse': isStaffMessage(msg) }">
                            <div
                                v-if="!isStaffMessage(msg)"
                                class="avatar-circle bg-primary text-white d-flex align-items-center justify-content-center flex-shrink-0"
                                style="width: 36px; height: 36px; font-size: 0.85rem;"
                            >
                                {{ authorName(msg).charAt(0).toUpperCase() }}
                            </div>

                            <div class="d-flex flex-column" style="max-width: 100%;">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="fw-medium small">{{ authorName(msg) }}</span>
                                    <span class="badge bg-light text-dark border small">{{ authorRole(msg) }}</span>
                                    <span class="text-muted small">{{ formatRelativeTime(msg.created_at) }}</span>
                                </div>

                                <div
                                    class="bg-white rounded-3 p-3 shadow-sm"
                                    :class="{ 'border-warning': isInternalNote(msg) }"
                                    style="border-left: 3px solid #0d6efd;"
                                >
                                    <div v-if="msg.body_text" class="mb-2" style="white-space: pre-wrap;">{{ msg.body_text }}</div>

                                    <div v-if="msg.attachments && msg.attachments.length > 0" class="mt-2">
                                        <div class="fw-small text-muted mb-1">Adjuntos:</div>
                                        <div
                                            v-for="att in msg.attachments"
                                            :key="att.id"
                                            class="attachment-item d-flex align-items-center gap-2 p-2 bg-light rounded mb-1"
                                        >
                                            <i
                                                class="bi me-2"
                                                :class="ATTACHMENT_ICONS[attachmentKind(att)]"
                                                style="font-size: 1.2rem;"
                                                aria-hidden="true"
                                            />
                                            <div class="flex-grow-1 overflow-hidden">
                                                <div class="fw-medium small text-truncate">{{ att.original_name }}</div>
                                                <div class="text-muted small">
                                                    {{ formatBytes(att.size_bytes) }}
                                                </div>
                                            </div>
                                            <div class="d-flex gap-1">
                                                <button
                                                    v-if="attachmentKind(att) === 'audio'"
                                                    type="button"
                                                    class="btn btn-sm btn-outline-primary"
                                                    :aria-label="`Reproducir ${att.original_name}`"
                                                    @click="playAudio(att)"
                                                >
                                                    <i class="bi bi-play-fill" aria-hidden="true" />
                                                </button>
                                                <a
                                                    class="btn btn-sm btn-outline-secondary"
                                                    :href="attachmentUrl(att)"
                                                    :download="att.original_name"
                                                    :aria-label="`Descargar ${att.original_name}`"
                                                >
                                                    <i class="bi bi-download" aria-hidden="true" />
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div
                                v-if="isStaffMessage(msg)"
                                class="avatar-circle bg-secondary text-white d-flex align-items-center justify-content-center flex-shrink-0"
                                style="width: 36px; height: 36px; font-size: 0.85rem;"
                            >
                                {{ authorName(msg).charAt(0).toUpperCase() }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Composer -->
            <div v-if="canReply" class="border-top p-3 bg-white">
                <div v-if="showNoteForm" class="mb-3">
                    <div class="d-flex gap-2">
                        <textarea
                            v-model="noteText"
                            class="form-control flex-grow-1"
                            rows="2"
                            placeholder="Nota interna (no visible para el cliente)…"
                        />
                        <AppButton variant="secondary" :loading="sendingNote" @click="sendNote">
                            Enviar nota
                        </AppButton>
                        <AppButton
                            variant="ghost"
                            @click="showNoteForm = false; noteText = ''"
                        >
                            Cancelar
                        </AppButton>
                    </div>
                </div>

                <div class="d-flex gap-2 align-items-end">
                    <AppButton
                        v-if="!showNoteForm"
                        variant="secondary"
                        size="sm"
                        icon="bi-pen"
                        @click="showNoteForm = true"
                    >
                        Nota interna
                    </AppButton>

                    <template v-if="!isRecording">
                        <AppButton
                            variant="secondary"
                            size="sm"
                            icon="bi-mic"
                            :disabled="uploadingAttachment"
                            @click="startRecording"
                        >
                            Grabar audio
                        </AppButton>
                    </template>
                    <template v-else>
                        <AppButton variant="danger" size="sm" icon="bi-stop-circle" @click="stopRecording">
                            Detener ({{ audioDuration }} s)
                        </AppButton>
                        <AppButton variant="ghost" size="sm" @click="cancelRecording">
                            Descartar
                        </AppButton>
                    </template>

                    <div class="input-group flex-grow-1">
                        <input
                            ref="fileInput"
                            type="file"
                            class="form-control"
                            style="display: none;"
                            accept=".pdf,.jpg,.jpeg,.png,.csv,.docx,.xlsx,.webm,.ogg,.mp3,.m4a"
                            @change="handleFileUpload"
                        />
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            title="Adjuntar archivo"
                            aria-label="Adjuntar archivo"
                            :disabled="uploadingAttachment"
                            @click="triggerFileInput"
                        >
                            <span v-if="uploadingAttachment" class="spinner-border spinner-border-sm" aria-hidden="true" />
                            <i v-else class="bi bi-paperclip" aria-hidden="true" />
                        </button>
                        <input
                            v-model="replyText"
                            type="text"
                            class="form-control flex-grow-1"
                            placeholder="Escriba un mensaje…"
                            @keydown.enter.exact.prevent="sendMessage"
                        />
                        <AppButton :loading="sending" :disabled="!replyText.trim()" @click="sendMessage">
                            Enviar
                        </AppButton>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.messages-container {
    scroll-behavior: smooth;
}

.message-item {
    transition: background-color 0.2s;
}

.attachment-item {
    transition: all 0.2s ease;
}

.attachment-item:hover {
    background-color: #f8f9fa;
}

.input-group .form-control:focus {
    z-index: 3;
}

.message-item .btn-sm {
    padding: 0.125rem 0.5rem;
    font-size: 0.75rem;
}

@media (max-width: 768px) {
    .message-item {
        max-width: 100% !important;
    }

    .conversation-header .btn-group {
        flex-wrap: wrap;
    }
}
</style>
