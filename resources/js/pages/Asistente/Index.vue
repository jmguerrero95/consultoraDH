<template>
  <div class="min-h-screen bg-gray-50">
    <nav class="bg-white shadow-sm border-b">
      <div class="max-w-7xl mx-auto px-4 py-3">
        <div class="flex items-center justify-between">
          <h1 class="text-xl font-semibold text-gray-900">Asistente de IA</h1>
          
          <div class="flex items-center space-x-2">
            <span class="text-sm text-gray-500">Estado:</span>
            <span class="text-sm font-medium" :class="aiStatusClass">{{ aiStatus }}</span>
            <span class="text-sm text-gray-500">| Modelo:</span>
            <span class="text-sm text-gray-500">{{ aiModel || '--' }}</span>
          </div>
        </div>
      </div>
    </nav>

    <main class="max-w-7xl mx-auto p-4">
      <div class="pace">
        <!-- Conversation selector -->
        <div class="bg-white p-4 rounded-md shadow-sm mb-4">
          <div class="flex items-center space-x-4">
            <select v-model="selectedConversationId" class="rounded border border-gray-300 px-3 py-2" @change="loadMessages">
              <option value="">Nueva conversación</option>
              <option v-for="c in conversations" :key="c.id" :value="c.id">
                {{ c.title || 'Sin título' }} ({{ formatDate(c.created_at) }})
              </option>
            </select>
            <button v-if="selectedConversationId" @click="startNewConversation" class="text-sm text-primary hover:underline">
              Nueva conversación
            </button>
          </div>
        </div>

        <!-- Message history -->
        <div class="bg-white rounded-md shadow-sm overflow-hidden mb-4" style="max-height: 400px; overflow-y: auto;">
          <div class="p-4 space-y-4" v-if="messages.length > 0">
            <div v-for="msg in messages" :key="msg.id" class="flex" :class="msg.role === 'user' ? 'justify-end' : 'justify-start'">
              <div :class="[
                'max-w-[70%] rounded-lg px-4 py-2',
                msg.role === 'user' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-900'
              ]">
                <p class="whitespace-pre-wrap">{{ msg.content }}</p>
                <p class="text-xs mt-1 opacity-70">{{ formatDate(msg.created_at) }}</p>
              </div>
            </div>
          </div>
          <div v-else class="p-4 text-center text-gray-400">
            <p>No hay mensajes. Escribe un mensaje para comenzar.</p>
          </div>
        </div>

        <!-- Web sources -->
        <div v-if="webSources.length > 0" class="bg-blue-50 rounded-md p-4 mb-4">
          <h3 class="text-sm font-medium text-blue-800 mb-2">Fuentes web</h3>
          <ul class="space-y-1">
            <li v-for="src in webSources" :key="src.url" class="text-sm">
              <a :href="src.url" target="_blank" class="text-blue-600 hover:underline">{{ src.title || src.url }}</a>
              <span class="text-gray-400 ml-2">({{ src.domain }})</span>
            </li>
          </ul>
        </div>

        <!-- Artifacts -->
        <div v-if="artifacts.length > 0" class="bg-green-50 rounded-md p-4 mb-4">
          <h3 class="text-sm font-medium text-green-800 mb-2">Artefactos generados</h3>
          <ul class="space-y-1">
            <li v-for="art in artifacts" :key="art.id" class="text-sm">
              <a :href="art.download_url" class="text-green-600 hover:underline">{{ art.filename }}</a>
              <span class="text-gray-400 ml-2">({{ formatBytes(art.size_bytes) }})</span>
            </li>
          </ul>
        </div>

        <!-- Pending proposals -->
        <div v-if="pendingProposals.length > 0" class="bg-yellow-50 rounded-md p-4 mb-4">
          <h3 class="text-sm font-medium text-yellow-800 mb-2">Propuestas pendientes</h3>
          <div v-for="prop in pendingProposals" :key="prop.id" class="mb-3 p-3 bg-white rounded border">
            <p class="text-sm font-medium">{{ prop.tool }}</p>
            <p class="text-xs text-gray-500 mb-2">{{ JSON.stringify(prop.args, null, 2) }}</p>
            <div class="flex gap-2">
              <button @click="confirmProposal(prop.id)" class="px-3 py-1 bg-green-600 text-white rounded text-sm hover:bg-green-700">Confirmar</button>
              <button @click="rejectProposal(prop.id)" class="px-3 py-1 bg-red-600 text-white rounded text-sm hover:bg-red-700">Rechazar</button>
            </div>
          </div>
        </div>

        <!-- Error display -->
        <div v-if="error" class="bg-red-50 rounded-md p-4 mb-4 text-red-700 text-sm">
          {{ error }}
        </div>

        <!-- Message form -->
        <div class="bg-white p-4 rounded-md shadow-sm">
          <form @submit.prevent="sendMessage" class="flex gap-2">
            <textarea
              v-model="newMessage"
              placeholder="Escribe un mensaje..."
              class="flex-1 rounded border border-gray-300 px-3 py-2 focus:outline-none focus:border-primary resize-none"
              :disabled="sending"
              rows="2"
            ></textarea>
            <button 
              type="submit" 
              :disabled="!newMessage.trim() || sending"
              class="rounded border border-primary px-4 py-2 text-white font-medium hover:bg-primary/90 disabled:opacity-50 disabled:cursor-not-allowed self-end"
            >
              {{ sending ? 'Enviando...' : 'Enviar' }}
            </button>
          </form>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useApi } from '@/composables/useApi'

const sending = ref(false)
const newMessage = ref('')
const conversations = ref([])
const selectedConversationId = ref('')
const messages = ref([])
const webSources = ref([])
const artifacts = ref([])
const pendingProposals = ref([])
const aiStatus = ref('Desconocido')
const aiModel = ref('--')
const aiStatusClass = ref('text-gray-500')
const error = ref(null)

const { api } = useApi()

onMounted(async () => {
  await checkAiStatus()
  await loadConversations()
})

async function checkAiStatus() {
  try {
    const response = await api.get('/ai/status')
    const data = response.data
    aiStatus.value = data.ai_enabled ? 'Activo' : 'Inactivo'
    aiStatusClass.value = data.ai_enabled ? 'text-green-600' : 'text-red-600'
    aiModel.value = data.primary?.model || '--'
  } catch (e) {
    aiStatus.value = 'Error'
    aiStatusClass.value = 'text-red-600'
  }
}

async function loadConversations() {
  try {
    const response = await api.get('/ai/conversations')
    conversations.value = response.data.conversations || []
  } catch (e) {
    console.error('Error loading conversations:', e)
  }
}

async function loadMessages() {
  if (!selectedConversationId.value) {
    messages.value = []
    webSources.value = []
    artifacts.value = []
    pendingProposals.value = []
    return
  }

  try {
    const response = await api.get(`/ai/conversations/${selectedConversationId.value}`)
    messages.value = response.data.messages || []
  } catch (e) {
    console.error('Error loading messages:', e)
    error.value = 'Error al cargar mensajes'
  }
}

function startNewConversation() {
  selectedConversationId.value = ''
  messages.value = []
  webSources.value = []
  artifacts.value = []
  pendingProposals.value = []
}

async function sendMessage() {
  if (!newMessage.value.trim() || sending.value) return

  const msg = newMessage.value
  newMessage.value = ''
  sending.value = true
  error.value = null

  try {
    let response
    if (selectedConversationId.value) {
      response = await api.post(`/ai/conversations/${selectedConversationId.value}/messages`, {
        message: msg
      })
    } else {
      response = await api.post('/ai/conversations', {
        question: msg
      })
      selectedConversationId.value = response.data.conversation_id
      await loadConversations()
    }

    const data = response.data
    messages.value = data.messages || []
    webSources.value = data.tools || []
    artifacts.value = data.artifacts || []
    pendingProposals.value = data.proposals || []

    // Update message list to include new messages
    if (data.reply) {
      // The service returns all messages, so we just use what it returns
    }
  } catch (e) {
    console.error('Error sending message:', e)
    error.value = e.response?.data?.message || 'Error al enviar mensaje'
  } finally {
    sending.value = false
  }
}

async function confirmProposal(proposalId) {
  try {
    await api.post(`/ai/action-proposals/${proposalId}/confirm`)
    // Reload to get updated state
    await sendMessage()
  } catch (e) {
    error.value = 'Error al confirmar propuesta'
  }
}

async function rejectProposal(proposalId) {
  try {
    await api.post(`/ai/action-proposals/${proposalId}/reject`)
    pendingProposals.value = pendingProposals.value.filter(p => p.id !== proposalId)
  } catch (e) {
    error.value = 'Error al rechazar propuesta'
  }
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  return date.toLocaleString('es-ES', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

function formatBytes(bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
</script>

<style scoped>
/* No additional styles needed - using Tailwind classes */
</style>