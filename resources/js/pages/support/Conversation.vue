<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import { useRealtime } from '@/composables/useRealtime';
import { api } from '@/services/api';

interface Conversation {
  id: number;
  subject: string;
  status: string;
  priority: string;
  origin_channel: string;
  queue: { id: number; name: string };
  assignee?: { id: number; name: string };
  client: { id: number; first_names: string; last_names: string; document_number: string };
  last_message_at: string;
  unread_count: number;
  messages?: Message[];
}

interface Message {
  id: number;
  body_text: string;
  sender_kind: 'staff' | 'client' | 'external' | 'system';
  message_kind: 'message' | 'note' | 'system';
  channel: string;
  client_visible: boolean;
  created_at: string;
  author?: { id: number; name: string };
  attachments?: Attachment[];
}

interface Attachment {
  id: number;
  kind: 'file' | 'audio';
  original_name: string;
  mime_type: string;
  size_bytes: number;
  stored_path: string;
  sha256: string;
}

const route = useRoute();
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
const audioRecorder = ref<MediaRecorder | null>(null);
const audioChunks = ref<Blob[]>([]);
const isRecording = ref(false);
const audioDuration = ref(0);
const recordingTimer = ref<number | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

const queues = ref<{ id: number; name: string }[]>([]);
const staffUsers = ref<{ id: number; name: string }[]>([]);
const hasMoreMessages = ref(false);

const priorityOptions = [
  { value: 'normal', label: 'Normal' },
  { value: 'high', label: 'Alta' },
  { value: 'urgent', label: 'Urgente' },
];

const formatRelativeTime = (dateString: string): string => {
  const date = new Date(dateString);
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffMins = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMs / 3600000);
  const diffDays = Math.floor(diffMs / 86400000);

  if (diffMins < 1) return 'Ahora';
  if (diffMins < 60) return `hace ${diffMins} min`;
  if (diffHours < 24) return `hace ${diffHours} h`;
  if (diffDays < 7) return `hace ${diffDays} d`;
  return date.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

const statusLabel = (status: string | undefined) => {
  if (!status) return '';
  const labels: Record<string, string> = {
    open: 'Abierta',
    waiting_staff: 'Esperando staff',
    waiting_client: 'Esperando cliente',
    resolved: 'Resuelta',
    closed: 'Cerrada',
  };
  return labels[status] || status;
};

const priorityLabel = (priority: string | undefined) => {
  if (!priority) return '';
  const labels: Record<string, string> = {
    normal: 'Normal',
    high: 'Alta',
    urgent: 'Urgente',
  };
  return labels[priority] || priority;
};

const isInternalNote = (msg: Message) => msg.message_kind === 'note';
const isStaffMessage = (msg: Message) => msg.sender_kind === 'staff';

const statusBadgeClass = (status: string | undefined) => {
  if (!status) return 'bg-gray-100 text-gray-800';
  const classes: Record<string, string> = {
    open: 'bg-gray-100 text-gray-800',
    waiting_staff: 'bg-yellow-100 text-yellow-800',
    waiting_client: 'bg-blue-100 text-blue-800',
    resolved: 'bg-green-100 text-green-800',
    closed: 'bg-gray-100 text-gray-600',
  };
  return classes[status] || 'bg-gray-100 text-gray-800';
};

const priorityBadgeClass = (priority: string | undefined) => {
  if (!priority) return 'bg-gray-100 text-gray-800';
  const classes: Record<string, string> = {
    normal: 'bg-gray-100 text-gray-800',
    high: 'bg-orange-100 text-orange-800',
    urgent: 'bg-red-100 text-red-800',
  };
  return classes[priority] || 'bg-gray-100 text-gray-800';
};

const formatBytes = (bytes: number) => {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

async function loadConversation() {
  loading.value = true;
  error.value = null;
  try {
    const response = await api.support.conversation(parseInt(route.params.id as string), { per_page: 50 });
    conversation.value = response.data;
    messages.value = response.data.messages?.reverse() || [];
    await loadMetadata();
  } catch (e) {
    error.value = 'Error al cargar la conversación';
    console.error(e);
  } finally {
    loading.value = false;
  }
}

async function loadMetadata() {
  try {
    const [queuesRes, staffRes] = await Promise.all([
      api.support.queues(),
      api.get('/users', { query: { account_type: 'staff', status: 'active' } })
    ]);
    queues.value = queuesRes.data;
    staffUsers.value = (staffRes.data as any).data.map((u: any) => ({ id: u.id, name: u.name }));
  } catch (e) {
    console.error('Error loading metadata:', e);
  }
}

async function loadMoreMessages() {
  if (!conversation.value || !hasMoreMessages.value) return;
  
  try {
    const lastMessage = messages.value[0];
    const response = await api.support.loadMoreMessages(conversation.value!.id, lastMessage.id, 50);
    const olderMessages = response.data.messages?.reverse() || [];
    messages.value = [...olderMessages, ...messages.value];
    hasMoreMessages.value = olderMessages.length >= 50;
  } catch (e) {
    console.error('Error loading more messages:', e);
  }
}

async function sendMessage() {
  if (!replyText.value.trim() || sending.value) return;
  
  sending.value = true;
  try {
    const response = await api.support.sendMessage(conversation.value!.id, {
      body_text: replyText.value.trim(),
    });
    const newMessage = response.data;
    messages.value.push(newMessage);
    replyText.value = '';
    scrollToBottom();
  } catch (e) {
    console.error('Error sending message:', e);
  } finally {
    sending.value = false;
  }
}

async function sendNote() {
  if (!noteText.value.trim() || sendingNote.value) return;
  
  sendingNote.value = true;
  try {
    const response = await api.support.sendNote(conversation.value!.id, {
      body_text: noteText.value.trim(),
    });
    const newNote = response.data;
    messages.value.push(newNote);
    noteText.value = '';
    showNoteForm.value = false;
    scrollToBottom();
  } catch (e) {
    console.error('Error sending note:', e);
  } finally {
    sendingNote.value = false;
  }
}

async function changePriority() {
  if (!newPriority.value) return;
  
  try {
    await api.support.changePriority(conversation.value!.id, newPriority.value);
    conversation.value!.priority = newPriority.value;
  } catch (e) {
    console.error('Error changing priority:', e);
  }
}

async function changeQueue() {
  if (!newQueueId.value) return;
  
  try {
    const payload: any = { queue_id: newQueueId.value };
    if (newAssigneeId.value) {
      payload.assignee_user_id = newAssigneeId.value;
    }
    await api.support.changeQueue(conversation.value!.id, payload);
    await loadConversation();
  } catch (e) {
    console.error('Error changing queue:', e);
  } finally {
    newQueueId.value = null;
    newAssigneeId.value = null;
  }
}

async function resolveConversation() {
  if (!confirm('¿Marcar como resuelta?')) return;
  
  try {
    await api.support.resolveConversation(conversation.value!.id);
    conversation.value!.status = 'resolved';
  } catch (e) {
    console.error('Error resolving conversation:', e);
  }
}

async function closeConversation() {
  if (!confirm('¿Cerrar definitivamente?')) return;
  
  try {
    await api.support.closeConversation(conversation.value!.id);
    conversation.value!.status = 'closed';
  } catch (e) {
    console.error('Error closing conversation:', e);
  }
}

async function reopenConversation() {
  if (!confirm('¿Reabrir conversación?')) return;
  
  try {
    await api.support.reopenConversation(conversation.value!.id);
    await loadConversation();
  } catch (e) {
    console.error('Error reopening conversation:', e);
  }
}

async function assignConversation(userId: number | null) {
  try {
    await api.support.assignConversation(conversation.value!.id, userId);
    await loadConversation();
  } catch (e) {
    console.error('Error assigning conversation:', e);
  }
}

function startRecording() {
  navigator.mediaDevices.getUserMedia({ audio: true })
    .then(stream => {
      audioRecorder.value = new MediaRecorder(stream);
      audioChunks.value = [];
      
      audioRecorder.value.ondataavailable = (e) => {
        if (e.data.size > 0) audioChunks.value.push(e.data);
      };
      
      audioRecorder.value.onstop = () => {
        const blob = new Blob(audioChunks.value, { type: 'audio/webm' });
        uploadAudio(blob);
      };
      
      audioRecorder.value.start();
      isRecording.value = true;
      audioDuration.value = 0;
      
      recordingTimer.value = window.setInterval(() => {
        audioDuration.value++;
      }, 1000);
    })
    .catch(err => {
      console.error('Error accessing microphone:', err);
      alert('No se pudo acceder al micrófono');
    });
}

async function uploadAudio(blob: Blob) {
  const file = new File([blob], `audio_${Date.now()}.webm`, { type: 'audio/webm' });
  await uploadAttachment(file);
}

async function uploadAttachment(file: File) {
  try {
    const response = await api.support.uploadAttachment(conversation.value!.id, file);
    const attachment = response.data;
    await sendMessageWithAttachment(attachment.id);
  } catch (e) {
    console.error('Error uploading attachment:', e);
  }
}

function triggerFileInput() {
  fileInput.value?.click();
}

function handleFileUpload(event: Event) {
  const input = event.target as HTMLInputElement;
  if (input.files && input.files[0]) {
    uploadAttachment(input.files[0]);
    input.value = '';
  }
}

async function sendMessageWithAttachment(attachmentId: number) {
  try {
    await api.support.sendMessageWithAttachment(conversation.value!.id, {
      body_text: 'Mensaje de voz',
      attachment_ids: [attachmentId]
    });
    await loadConversation();
  } catch (e) {
    console.error('Error sending audio message:', e);
  }
}

function playAudio(blobUrl: string | null) {
  if (!blobUrl) return;
  const audio = new Audio(blobUrl);
  audio.play();
}

function formatDuration(seconds: number): string {
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  return `${mins}:${secs.toString().padStart(2, '0')}`;
}

function scrollToBottom() {
  const container = document.querySelector('.messages-container');
  if (container) {
    container.scrollTop = container.scrollHeight;
  }
}

onMounted(() => {
  loadConversation();
  
  const conversationId = parseInt(route.params.id as string);
  
  // Subscribe to realtime
  const unsubscribe = realtime.subscribeToConversation(conversationId, {
    onMessage: (data) => {
      if (data.message) {
        messages.value.push(data.message);
        scrollToBottom();
      }
    },
    onReadUpdate: (_data) => {
      // Update unread counts
    },
    onConversationUpdate: (data) => {
      if (conversation.value) {
        conversation.value = { ...conversation.value, ...data.conversation };
      }
    },
  });
  
  // Load more messages when scrolling up
  const container = document.querySelector('.messages-container');
  if (container) {
    container.addEventListener('scroll', () => {
      if (container.scrollTop === 0 && hasMoreMessages.value && !loading.value) {
        loadMoreMessages();
      }
    });
  }
  
  // Auto-scroll on new messages
  watch(messages, () => {
    nextTick(() => scrollToBottom());
  }, { deep: true });
  
  // Store cleanup function
  (window as any).__unsubscribeRealtime = unsubscribe;
});

onUnmounted(() => {
  if ((window as any).__unsubscribeRealtime) {
    (window as any).__unsubscribeRealtime();
  }
  if (recordingTimer.value) clearInterval(recordingTimer.value);
});
</script>

<template>
  <div class="support-conversation h-100 d-flex flex-column">
    <div v-if="loading" class="d-flex justify-content-center align-items-center flex-grow-1">
      <div class="spinner-border text-primary" role="status">
        <span class="visually-hidden">Cargando...</span>
      </div>
    </div>

    <div v-else-if="error" class="alert alert-danger">
      {{ error }}
    </div>

    <div v-else class="h-100 d-flex flex-column">
      <!-- Header -->
      <div class="conversation-header border-bottom px-3 py-3 bg-white">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <h2 class="h5 mb-1">{{ conversation?.subject }}</h2>
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span :class="statusBadgeClass(conversation?.status)" class="badge px-2 py-1">
                {{ statusLabel(conversation?.status) }}
              </span>
              <span :class="priorityBadgeClass(conversation?.priority)" class="badge px-2 py-1">
                {{ priorityLabel(conversation?.priority) }}
              </span>
              <span class="badge bg-secondary text-white">
                {{ conversation?.queue?.name }}
              </span>
              <span v-if="conversation?.assignee" class="badge bg-info text-white">
                {{ conversation.assignee.name }}
              </span>
              <span v-else class="badge bg-secondary text-white">Sin asignar</span>
            </div>
          </div>
          <div class="d-flex gap-2">
            <button v-if="conversation?.status !== 'closed' && conversation?.status !== 'resolved'" 
                    class="btn btn-outline-primary btn-sm" @click="resolveConversation">
              <i class="bi bi-check-lg me-1"></i> Resolver
            </button>
            <button v-if="conversation?.status === 'resolved'" 
                    class="btn btn-outline-warning btn-sm" @click="reopenConversation">
              <i class="bi bi-arrow-counterclockwise me-1"></i> Reabrir
            </button>
            <button v-if="conversation?.status !== 'closed'" 
                    class="btn btn-outline-danger btn-sm" @click="closeConversation">
              <i class="bi bi-x-lg me-1"></i> Cerrar
            </button>
          </div>
        </div>

        <div class="d-flex gap-2 mt-2 flex-wrap">
          <div class="btn-group" role="group">
            <button v-for="p in priorityOptions" :key="p.value"
                    :class="['btn', conversation?.priority === p.value ? 'btn-primary' : 'btn-outline-secondary', 'btn-sm']"
                    @click="newPriority = p.value; changePriority()">
              {{ p.label }}
            </button>
          </div>
          
          <div class="dropdown ms-2">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
              <i class="bi bi-folder2 me-1"></i> Cambiar cola
            </button>
            <ul class="dropdown-menu">
              <li v-for="q in queues" :key="q.id">
                <button class="dropdown-item" @click="newQueueId = q.id; changeQueue()">
                  {{ q.name }}
                </button>
              </li>
            </ul>
          </div>
          
          <div class="dropdown ms-2" v-if="staffUsers.length > 0">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
              <i class="bi bi-person-plus me-1"></i> Asignar
            </button>
            <ul class="dropdown-menu">
              <li><button class="dropdown-item" @click="assignConversation(null)">Sin asignar</button></li>
              <li v-for="u in staffUsers" :key="u.id">
                <button class="dropdown-item" @click="assignConversation(u.id)">
                  {{ u.name }}
                </button>
              </li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Messages Area -->
      <div class="flex-grow-1 overflow-auto messages-container p-3" style="background-color: #f5f7fa;">
        <div v-if="messages.length === 0" class="text-center text-muted py-5">
          <i class="bi bi-chat display-1"></i>
          <p class="mt-3">No hay mensajes en esta conversación</p>
        </div>
        
        <div v-else>
          <button v-if="hasMoreMessages && !loading" 
                  class="btn btn-sm btn-outline-secondary d-block mx-auto mb-3"
                  @click="loadMoreMessages">
            <i class="bi bi-chevron-up me-1"></i> Cargar más mensajes
          </button>
          
          <div v-for="msg in messages" :key="msg.id" class="message-item mb-3"
               :class="{ 'text-end': isStaffMessage(msg), 'ms-auto': isStaffMessage(msg), 'me-auto': !isStaffMessage(msg) }"
               style="max-width: 80%;">
            <div class="d-flex gap-2" :class="{ 'flex-row-reverse': isStaffMessage(msg) }">
              <div v-if="!isStaffMessage(msg)" class="avatar-circle bg-primary text-white d-flex align-items-center justify-content-center flex-shrink-0"
                   style="width: 36px; height: 36px; font-size: 0.85rem;">
                {{ msg.author?.name?.charAt(0).toUpperCase() || 'C' }}
              </div>
              
              <div class="d-flex flex-column" style="max-width: 100%;">
                <div class="d-flex align-items-center gap-2 mb-1">
                  <span class="fw-medium small">{{ isStaffMessage(msg) ? (msg.author?.name || 'Staff') : (msg.author?.name || 'Cliente') }}</span>
                  <span class="text-muted small">{{ formatRelativeTime(msg.created_at) }}</span>
                  <span v-if="isInternalNote(msg)" class="badge bg-warning text-dark small">Nota interna</span>
                  <span v-else-if="msg.sender_kind === 'system'" class="badge bg-info text-white small">Sistema</span>
                  <span v-else-if="msg.sender_kind === 'external'" class="badge bg-secondary text-white small">Externo</span>
                </div>
                
                <div class="bg-white rounded-3 p-3 shadow-sm"
                     :class="{ 'border-warning': isInternalNote(msg) }"
                     style="border-left: 3px solid #0d6efd;">
                  <div v-if="msg.body_text" class="mb-2" style="white-space: pre-wrap;">{{ msg.body_text }}</div>
                  
                  <div v-if="msg.attachments && msg.attachments.length > 0" class="mt-2">
                    <div class="fw-small text-muted mb-1">Adjuntos:</div>
                    <div v-for="att in msg.attachments" :key="att.id" class="attachment-item d-flex align-items-center gap-2 p-2 bg-light rounded mb-1">
                      <i :class="[
                        'bi me-2',
                        att.kind === 'audio' ? 'bi-file-music text-primary' : 
                        att.mime_type.startsWith('image/') ? 'bi-file-image text-info' : 
                        att.mime_type.startsWith('application/pdf') ? 'bi-file-pdf text-danger' :
                        'bi-file-earmark text-secondary'
                      ]" style="font-size: 1.2rem;"></i>
                      <div class="flex-grow-1 overflow-hidden">
                        <div class="fw-medium small text-truncate">{{ att.original_name }}</div>
                        <div class="text-muted small">{{ formatBytes(att.size_bytes) }}</div>
                      </div>
                      <div class="d-flex gap-1">
                        <button v-if="att.kind === 'audio'" class="btn btn-sm btn-outline-primary" @click="playAudio(att.stored_path)">
                          <i class="bi bi-play-fill"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" 
                                :href="'/api/support/conversations/' + conversation?.id + '/attachments/' + att.id"
                                download>
                          <i class="bi bi-download"></i>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              
              <div v-if="isStaffMessage(msg)" class="avatar-circle bg-secondary text-white d-flex align-items-center justify-content-center flex-shrink-0"
                   style="width: 36px; height: 36px; font-size: 0.85rem;">
                {{ msg.author?.name?.charAt(0).toUpperCase() || 'S' }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Message Input Area -->
      <div class="border-top p-3 bg-white">
        <!-- Internal Note Form -->
        <div v-if="showNoteForm" class="mb-3">
          <div class="d-flex gap-2">
            <textarea v-model="noteText" class="form-control flex-grow-1" rows="2" 
                      placeholder="Nota interna (no visible para el cliente)..." 
                      @keydown.enter.exact.prevent="sendNote"></textarea>
            <button class="btn btn-warning" @click="sendNote" :disabled="sendingNote || !noteText.trim()">
              <span v-if="sendingNote" class="spinner-border spinner-border-sm me-1"></span>
              Enviar nota
            </button>
            <button class="btn btn-outline-secondary" @click="showNoteForm = false; noteText = ''">
              Cancelar
            </button>
          </div>
        </div>
        
        <div class="d-flex gap-2">
          <button v-if="!showNoteForm" class="btn btn-outline-warning btn-sm" @click="showNoteForm = true">
            <i class="bi bi-pen me-1"></i> Nota interna
          </button>
          
          <button class="btn btn-outline-secondary btn-sm" @click="startRecording" :disabled="isRecording">
            <i v-if="!isRecording" class="bi bi-mic me-1"></i>
            <i v-else class="bi bi-stop-circle me-1"></i>
            {{ isRecording ? formatDuration(audioDuration) : 'Audio' }}
          </button>
          
          <div class="input-group flex-grow-1">
            <input type="file" ref="fileInput" class="form-control" style="display: none;" 
                   accept=".pdf,.jpg,.jpeg,.png,.csv,.docx,.xlsx,.webm,.ogg,.mp3,.mp4"
                   @change="handleFileUpload">
            <button class="btn btn-outline-secondary" @click="triggerFileInput" 
                    title="Adjuntar archivo">
              <i class="bi bi-paperclip"></i>
            </button>
            <input type="text" v-model="replyText" class="form-control flex-grow-1" 
                   placeholder="Escribe un mensaje..." 
                   @keydown.enter.exact.prevent="sendMessage">
            <button class="btn btn-primary" @click="sendMessage" :disabled="sending || !replyText.trim()">
              <span v-if="sending" class="spinner-border spinner-border-sm me-1"></span>
              Enviar
            </button>
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