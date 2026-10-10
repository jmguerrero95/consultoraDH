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
const replyText = ref('');
const audioRecorder = ref<MediaRecorder | null>(null);
const audioChunks = ref<Blob[]>([]);
const isRecording = ref(false);
const audioDuration = ref(0);
const recordingTimer = ref<number | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

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

const isClientVisible = (msg: Message) => msg.client_visible;
const isInternalNote = (msg: Message) => msg.message_kind === 'note';
const isClientMessage = (msg: Message) => msg.sender_kind === 'client';
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

const formatDuration = (seconds: number): string => {
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  return `${mins}:${secs.toString().padStart(2, '0')}`;
};

function scrollToBottom() {
  const container = document.querySelector('.messages-container');
  if (container) {
    container.scrollTop = container.scrollHeight;
  }
}

async function loadConversation() {
  loading.value = true;
  error.value = null;
  try {
    const response = await api.support.conversation(parseInt(route.params.id as string), {});
    conversation.value = response.data;
    messages.value = response.data.messages || [];
  } catch (e) {
    error.value = 'Error al cargar la conversación';
    console.error(e);
  } finally {
    loading.value = false;
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

async function uploadAttachment(file: File) {
  try {
    const response = await api.support.uploadAttachment(conversation.value!.id, file);
    const attachment = response.data;
    await sendMessageWithAttachment(attachment.id);
  } catch (e) {
    console.error('Error uploading attachment:', e);
  }
}

async function sendMessageWithAttachment(attachmentId: number) {
  try {
    await api.support.sendMessageWithAttachment(conversation.value!.id, {
      body_text: 'Adjunto',
      attachment_ids: [attachmentId]
    });
    await loadConversation();
  } catch (e) {
    console.error('Error sending message with attachment:', e);
  }
}

function playAudio(blobUrl: string | null) {
  if (!blobUrl) return;
  const audio = new Audio(blobUrl);
  audio.play();
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
  <div class="portal-support-conversation h-100 d-flex flex-column">
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
          </div>
        </div>
      </div>
    </div>

    <!-- Messages Area -->
    <div class="flex-grow-1 overflow-auto messages-container p-3">
      <div v-if="loading" class="text-center py-5">
        <div class="spinner-border text-primary" role="status">
          <span class="visually-hidden">Cargando...</span>
        </div>
      </div>

      <div v-else-if="error" class="alert alert-danger">
        {{ error }}
      </div>

      <div v-else class="messages-list">
<div v-for="msg in messages" :key="msg.id" 
             :class="[
                'message-item mb-3 p-3 rounded',
                isClientMessage(msg) ? 'bg-light' : 'bg-white',
                isInternalNote(msg) ? 'border-start border-3 border-warning' : '',
                !isClientVisible(msg) ? 'opacity-75' : '',
                isClientMessage(msg) ? 'ms-auto' : 'me-auto'
              ]"
              style="max-width: 85%;">
          
          <div class="d-flex justify-content-between align-items-start mb-1">
            <div>
              <span v-if="isInternalNote(msg)" class="badge bg-warning text-dark me-2">
                <i class="bi bi-lock me-1"></i> Nota interna
              </span>
              <strong>{{ msg.author?.name || (isStaffMessage(msg) ? 'Staff' : 'Yo') }}</strong>
              <span class="text-muted ms-2 small">{{ formatRelativeTime(msg.created_at) }}</span>
              <span v-if="msg.channel === 'email'" class="badge bg-info ms-2">Correo</span>
            </div>
          </div>
          
          <div class="message-content">
            <p class="mb-2" v-if="msg.body_text">{{ msg.body_text }}</p>
            
            <div v-if="msg.attachments && msg.attachments.length > 0" class="d-flex flex-wrap gap-2 mt-2">
              <div v-for="att in msg.attachments" :key="att.id" class="attachment-item">
                <div class="d-flex align-items-center p-2 border rounded bg-white" style="min-width: 200px; max-width: 300px;">
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
                            :href="'/api/portal/support/conversations/' + conversation?.id + '/attachments/' + att.id"
                            download>
                      <i class="bi bi-download"></i>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Message Input Area -->
    <div class="border-top p-3 bg-white">
      <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" @click="startRecording" :disabled="isRecording">
          <i v-if="!isRecording" class="bi bi-mic me-1"></i>
          <i v-else class="bi bi-stop-circle me-1"></i>
          {{ isRecording ? formatDuration(audioDuration) : 'Audio' }}
        </button>
        
        <div class="input-group flex-grow-1">
          <input type="file" ref="fileInput" class="form-control" style="display: none;" 
                 accept=".pdf,.jpg,.jpeg,.png,.csv,.docx,.xlsx,.webm,.ogg,.mp3,.mp4"
                 @change="handleFileUpload">
          <button class="btn btn-outline-secondary" @click="triggerFileInput" title="Adjuntar archivo">
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