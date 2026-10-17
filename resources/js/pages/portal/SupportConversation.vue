<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import { api } from '@/services/api';
import { useRealtime } from '@/composables/useRealtime';

/**
 * The client's side of a support conversation, and the form that opens one.
 *
 * ## Why create mode is on this screen
 *
 * `portal.support.create` and `portal.support.show` point at this component, but it read
 * the id from the route and asked the server for it unconditionally. On the create route
 * there is no id: `parseInt(undefined)` is `NaN`, the request failed, and the screen
 * showed "Error al cargar la conversación" with no way to open one. The client portal has
 * had a working `store` endpoint all along; nothing ever called it.
 */

interface Conversation {
  id: number;
  subject: string;
  status: string;
  priority: string;
  origin_channel: string;
  queue?: { id: number; name: string } | null;
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
const realtime = useRealtime();

const conversation = ref<Conversation | null>(null);
const messages = ref<Message[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const sending = ref(false);
const replyText = ref('');
const actionError = ref<string | null>(null);

const audioRecorder = ref<MediaRecorder | null>(null);
const audioStream = ref<MediaStream | null>(null);
const audioChunks = ref<Blob[]>([]);
const isRecording = ref(false);
const audioDuration = ref(0);
const recordingTimer = ref<number | null>(null);
const uploadingAttachment = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);

/** Create-mode state. */
const newSubject = ref('');
const newBody = ref('');
const creating = ref(false);
const createError = ref<string | null>(null);

/**
 * The conversation id, or `null` on the create screen.
 *
 * The single place `NaN` used to come from: `parseInt` of a missing route param, which then
 * travelled into the GET, the realtime channel name and every attachment URL.
 */
const conversationId = computed<number | null>(() => {
  const raw = route.params.id;

  if (raw === undefined || raw === null || raw === '') {
    return null;
  }

  const parsed = Number.parseInt(String(raw), 10);

  return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
});

const isCreateMode = computed(() => conversationId.value === null);

const STATUS_LABELS: Record<string, string> = {
  open: 'Abierta',
  waiting_staff: 'En revisión',
  waiting_client: 'Esperando tu respuesta',
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
 * `labels[status] || status` returned `''` for a missing or unknown value — an empty pill,
 * which reads as a broken layout rather than as "the server did not send this".
 */
const statusLabel = (status: string | undefined | null): string =>
  (status ? STATUS_LABELS[status] ?? status : '') || 'Sin estado';

const priorityLabel = (priority: string | undefined | null): string =>
  (priority ? PRIORITY_LABELS[priority] ?? priority : '') || 'Sin prioridad';

/**
 * A timestamp that never renders as `Invalid Date`.
 *
 * `new Date(undefined)` is `Invalid Date`; every subtraction is `NaN`, every comparison is
 * false, and the last branch printed "Invalid Date" into the thread.
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
 * Who wrote a message, from the client's point of view.
 *
 * The name used to come from `author.name` alone, which is null on anything the client wrote
 * or the e-mail ingress stored, so half the thread was attributed to nobody. The role is the
 * fallback, and the role is always shown.
 */
function authorName(msg: Message): string {
  if (msg.author?.name) {
    return msg.author.name;
  }

  return {
    staff: 'Equipo de soporte',
    client: 'Tú',
    external: 'Remitente externo',
    system: 'Sistema',
  }[msg.sender_kind] ?? 'Autor desconocido';
}

function authorRole(msg: Message): string {
  if (isInternalNote(msg)) return 'Nota interna';
  if (msg.sender_kind === 'system') return 'Sistema';
  if (msg.sender_kind === 'external') return 'Externo';
  if (msg.sender_kind === 'staff') return 'Soporte';
  return 'Tú';
}

const formatBytes = (bytes: number | null | undefined) => {
  const value = typeof bytes === 'number' && Number.isFinite(bytes) ? bytes : 0;

  if (value < 1024) return `${value} B`;
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;

  return `${(value / (1024 * 1024)).toFixed(1)} MB`;
};

const formatDuration = (seconds: number): string => {
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;

  return `${mins}:${secs.toString().padStart(2, '0')}`;
};

/**
 * What an attachment actually is, from the detected MIME type and the stored extension.
 *
 * The icon was chosen from `kind` and the name from `mime_type.startsWith(...)`, which threw
 * on a null MIME type and showed a voice note (stored as `kind: 'file'` before kinds were
 * recorded) as a generic file.
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
 * The real download URL for the portal endpoint.
 *
 * The control used to be a `<button>` carrying `:href` and `download`; a button has neither
 * attribute, so pressing it did nothing. It is an anchor now, and the audio player is handed
 * the same URL rather than `stored_path`, the private disk path it cannot fetch.
 */
function attachmentUrl(att: Attachment): string {
  return `/api/portal/support/conversations/${conversationId.value}/attachments/${att.id}`;
}

function playAudio(att: Attachment): void {
  const audio = new Audio(attachmentUrl(att));
  void audio.play().catch(() => {
    actionError.value = 'No se pudo reproducir el audio.';
  });
}

/** Ascending by time, ties broken by id, so the thread reads top to bottom. */
function orderMessages(list: Message[]): Message[] {
  return [...list].sort((a, b) => {
    const left = Date.parse(a.created_at);
    const right = Date.parse(b.created_at);
    const l = Number.isNaN(left) ? Number.MAX_SAFE_INTEGER : left;
    const r = Number.isNaN(right) ? Number.MAX_SAFE_INTEGER : right;

    return l === r ? a.id - b.id : l - r;
  });
}

async function loadConversation(): Promise<void> {
  const id = conversationId.value;

  if (id === null) {
    return;
  }

  loading.value = true;
  error.value = null;

  try {
    const response = (await api.portal.conversation(id, { per_page: 50 })) as unknown as Conversation;
    conversation.value = response;
    messages.value = orderMessages(response.messages ?? []);
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

async function markRead(): Promise<void> {
  const id = conversationId.value;

  if (id === null || messages.value.length === 0) {
    return;
  }

  const newest = messages.value[messages.value.length - 1];

  try {
    await api.portal.markRead(id, newest.id);
  } catch {
    // Read state is cosmetic; failing to record it must not fail the screen.
  }
}

async function sendMessage(): Promise<void> {
  const id = conversationId.value;

  if (id === null || !replyText.value.trim() || sending.value) return;

  sending.value = true;
  actionError.value = null;

  try {
    const newMessage = (await api.portal.sendMessage(id, { body_text: replyText.value.trim() })) as unknown as Message;
    appendIncoming(newMessage);
    replyText.value = '';
  } catch (e) {
    actionError.value = 'No se pudo enviar el mensaje. Intente de nuevo.';
    console.error('Error sending message:', e);
  } finally {
    sending.value = false;
  }
}

async function uploadAttachment(file: File, caption = 'Adjunto'): Promise<void> {
  const id = conversationId.value;

  if (id === null) return;

  uploadingAttachment.value = true;
  actionError.value = null;

  try {
    const attachment = (await api.portal.uploadAttachment(id, file)) as unknown as Attachment;
    const sent = (await api.portal.sendMessage(id, {
      body_text: caption,
      attachment_ids: [attachment.id],
    })) as unknown as Message;
    appendIncoming(sent);
  } catch (e) {
    actionError.value = 'No se pudo adjuntar el archivo.';
    console.error('Error uploading attachment:', e);
  } finally {
    uploadingAttachment.value = false;
  }
}

async function uploadAudio(blob: Blob): Promise<void> {
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const file = new File([blob], `nota-de-voz-${stamp}.webm`, { type: 'audio/webm' });
  await uploadAttachment(file, 'Mensaje de voz');
}

/**
 * Recording, stopping and releasing the microphone.
 *
 * The record button was `:disabled="isRecording"`, so it could never be stopped from the
 * interface; and the `MediaStream` was never closed, so the browser's recording indicator
 * stayed lit after the clip was sent. `stop` and `cancel` are separate, and every path
 * releases the tracks.
 */
let cancelledRecording = false;

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

/** Append a message, in order and without a duplicate from the socket echo. */
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

  if (newSubject.value.trim() === '' || newBody.value.trim() === '') {
    createError.value = 'El asunto y el mensaje son obligatorios.';
    return;
  }

  creating.value = true;

  try {
    const created = (await api.portal.createConversation({
      subject: newSubject.value.trim(),
      body_text: newBody.value.trim(),
    })) as unknown as Conversation;

    await router.replace({ name: 'portal.support.show', params: { id: created.id } });
  } catch (e) {
    createError.value = 'No se pudo crear la conversación. Intente de nuevo.';
    console.error('Error creating conversation:', e);
  } finally {
    creating.value = false;
  }
}

let cleanupRealtime: (() => void) | null = null;

onMounted(() => {
  void loadConversation();

  const id = conversationId.value;

  // No id means no conversation, so no channel — subscribing would ask the server to
  // authorise `support.conversation.NaN`.
  if (id === null) {
    return;
  }

  cleanupRealtime = realtime.subscribeToConversation(id, {
    onMessage: (data) => appendIncoming(data?.message ?? data),
    onNote: (data) => appendIncoming(data?.message ?? data),
    onConversationUpdate: (data) => {
      if (conversation.value && data?.conversation) {
        conversation.value = { ...conversation.value, ...data.conversation };
      }
    },
  });
});

watch(messages, () => {
  void nextTick(() => scrollToBottom());
}, { deep: true });

onUnmounted(() => {
  cleanupRealtime?.();
  releaseMicrophone();
});
</script>

<template>
  <div class="portal-support-conversation h-100 d-flex flex-column">
    <!-- Create mode: reached from the portal support inbox. -->
    <div v-if="isCreateMode" class="p-4" style="max-width: 640px;">
      <h1 class="h5 mb-3">Nueva conversación</h1>

      <AppAlert v-if="createError" variant="danger" :message="createError" class="mb-3" />

      <div class="mb-3">
        <label class="form-label" for="portal-support-subject">Asunto</label>
        <input
          id="portal-support-subject"
          v-model="newSubject"
          class="form-control"
          type="text"
          maxlength="255"
        />
      </div>

      <div class="mb-3">
        <label class="form-label" for="portal-support-body">Mensaje</label>
        <textarea id="portal-support-body" v-model="newBody" class="form-control" rows="5" />
      </div>

      <div class="d-flex gap-2">
        <AppButton :loading="creating" @click="createConversation">Enviar</AppButton>
        <AppButton variant="secondary" :to="{ name: 'portal.support' }">Cancelar</AppButton>
      </div>
    </div>

    <template v-else>
      <div class="conversation-header border-bottom px-3 py-3 bg-white">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <h2 class="h5 mb-1">{{ conversation?.subject || 'Conversación' }}</h2>
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span class="badge bg-secondary text-white">
                {{ statusLabel(conversation?.status) }}
              </span>
              <span class="badge bg-light text-dark border">
                {{ priorityLabel(conversation?.priority) }}
              </span>
              <span v-if="conversation?.queue?.name" class="badge bg-light text-dark border">
                {{ conversation.queue.name }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <div class="flex-grow-1 overflow-auto messages-container p-3">
        <div v-if="loading" class="text-center py-5">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Cargando…</span>
          </div>
        </div>

        <div v-else-if="error" class="p-3">
          <AppAlert variant="danger" :message="error" />
          <AppButton class="mt-3" variant="secondary" @click="loadConversation">Reintentar</AppButton>
        </div>

        <div v-else>
          <div v-if="messages.length === 0" class="text-center text-muted py-5">
            <i class="bi bi-chat display-4" aria-hidden="true" />
            <p class="mt-3">Aún no hay mensajes en esta conversación.</p>
          </div>

          <template v-else>
          <div
            v-for="msg in messages"
            :key="msg.id"
            class="message-item mb-3 p-3 rounded"
            :class="[
              isStaffMessage(msg) ? 'bg-white' : 'bg-light',
              isInternalNote(msg) ? 'border-start border-3 border-warning' : '',
            ]"
            style="max-width: 85%;"
          >
            <div class="d-flex justify-content-between align-items-start mb-1">
              <div>
                <span v-if="isInternalNote(msg)" class="badge bg-warning text-dark me-2">
                  <i class="bi bi-lock me-1" aria-hidden="true" /> Nota interna
                </span>
                <strong>{{ authorName(msg) }}</strong>
                <span class="badge bg-light text-dark border ms-2">{{ authorRole(msg) }}</span>
                <span class="text-muted ms-2 small">{{ formatRelativeTime(msg.created_at) }}</span>
                <span v-if="msg.channel === 'email'" class="badge bg-info text-white ms-2">Correo</span>
              </div>
            </div>

            <div class="message-content">
              <p v-if="msg.body_text" class="mb-2" style="white-space: pre-wrap;">{{ msg.body_text }}</p>

              <div v-if="msg.attachments && msg.attachments.length > 0" class="d-flex flex-wrap gap-2 mt-2">
                <div v-for="att in msg.attachments" :key="att.id" class="attachment-item">
                  <div class="d-flex align-items-center p-2 border rounded bg-white" style="min-width: 200px; max-width: 320px;">
                    <i
                      class="bi me-2"
                      :class="ATTACHMENT_ICONS[attachmentKind(att)]"
                      style="font-size: 1.2rem;"
                      aria-hidden="true"
                    />
                    <div class="flex-grow-1 overflow-hidden">
                      <div class="fw-medium small text-truncate">{{ att.original_name }}</div>
                      <div class="text-muted small">{{ formatBytes(att.size_bytes) }}</div>
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
          </div>
          </template>
        </div>
      </div>

      <div class="border-top p-3 bg-white">
        <AppAlert v-if="actionError" variant="danger" :message="actionError" class="mb-2" />

        <div class="d-flex gap-2">
          <AppButton
            v-if="!isRecording"
            variant="secondary"
            size="sm"
            icon="bi-mic"
            :disabled="uploadingAttachment"
            @click="startRecording"
          >
            Audio
          </AppButton>
          <template v-else>
            <AppButton variant="danger" size="sm" icon="bi-stop-circle" @click="stopRecording">
              Detener ({{ formatDuration(audioDuration) }})
            </AppButton>
            <AppButton variant="ghost" size="sm" @click="cancelRecording">Descartar</AppButton>
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
              placeholder="Escribe un mensaje…"
              @keydown.enter.exact.prevent="sendMessage"
            />
            <AppButton :loading="sending" :disabled="!replyText.trim()" @click="sendMessage">
              Enviar
            </AppButton>
          </div>
        </div>
      </div>
    </template>
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
}
</style>
