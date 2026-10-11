<template>
  <div class="min-h-screen bg-gray-50">
    <nav class="bg-white shadow-sm border-b">
      <div class="max-w-7xl mx-auto px-4 py-3">
        <div class="flex items-center justify-between">
          <h1 class="text-xl font-semibold text-gray-900">Asistente de IA</h1>
          
          <div class="flex items-center space-x-2">
            <span class="text-sm text-gray-500">Conectado como:</span>
            <span class="text-sm font-medium text-gray-900" id="user-name">
              Cargando...
            </span>
          </div>
        </div>
      </div>
    </nav>

    <main class="max-w-7xl mx-auto p-4">
      <div class="pace">
        <!-- Status bar -->
        <div class="bg-white p-4 rounded-md shadow-sm mb-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <p class="text-sm text-gray-500">Estado AI:</p>
              <span class="text-sm font-medium" id="ai-status">Desconocido</span>
            </p>
            <div>
              <p class="text-sm text-gray-500">Modelo:</p>
              <span class="text-sm text-gray-500" id="ai-model">--</span>
            </div>
          </div>
        </div>

        <!-- Conversation list -->
        <div class="mt-4">
          <h2 class="text-sm font-medium text-gray-500 mb-2">Conversaciones</h2>
          <div class="space-y-2" id="conversations-list">
            <p class="text-sm text-gray-400">Cargando conversaciones...</p>
          </div>
        </div>

        <!-- Message form -->
        <div class="mt-6 pt-4 border-t">
          <form @submit.prevent="sendMessage" class="flex gap-2">
            <input 
              v-model="newMessage" 
              type="text" 
              placeholder="Escribe un mensaje..."
              class="flex-1 rounded border border-gray-300 px-3 py-2 focus:outline-none focus:border-primary"
              :disabled="sending"
            />
            <button 
              type="submit" 
              :disabled="!newMessage.trim() || sending"
              class="rounded border border-primary px-4 py-2 text-white font-medium hover:bg-primary/90 disabled:opacity-50 disabled:cursor-not-allowed"
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
import { ref, onMounted, watch } from 'vue'

const sending = ref(false)
const newMessage = ref('')
const userName = ref('')

onMounted(async () => {
  // Check AI status
  await checkAiStatus()
  // Load conversations
  await loadConversations()
})

async function checkAiStatus() {
  try {
    const response = await fetch('/api/ai/status', {
      credentials: 'include'
    })
    const data = await response.json()
    document.getElementById('ai-status').textContent = data.ai_enabled ? 'Activo' : 'Inactivo'
    document.getElementById('ai-model').textContent = data.primary.model || '--'
  } catch (e) {
    document.getElementById('ai-status').textContent = 'Error'
  }
}

async function loadConversations() {
  try {
    const response = await fetch('/api/ai/conversations', {
      credentials: 'include'
    })
    const data = await response.json()
    const container = document.getElementById('conversations-list')
    if (data.conversations.length === 0) {
      container.innerHTML = '<p class="text-sm text-gray-400">No tienes conversaciones aún</p>'
      return
    }
    container.innerHTML = data.conversations.map((c, i) => `
      <div class="flex items-center justify-between px-2 py-1 rounded hover:bg-gray-100 transition">
        <span>${c.title || 'Sin título'}</span>
        <span class="text-xs text-gray-400">${c.created_at || ''}</span>
      </div>
    `).join('')
  } catch (e) {
    console.error('Error loading conversations:', e)
  }
}

const sendMessage = async (e) => {
  if (!newMessage.value.trim() || sending.value) return
  
  const msg = newMessage.value
  newMessage.value = ''
  sending.value = true
  
  try {
    const response = await fetch('/api/ai/conversations/messages', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ conversation_id: null, message: msg })
    })
    const data = await response.json()
    
    // In a real app, we'd update the UI with the reply
    // For now, just show that message was sent
    console.log('Mensaje enviado, respuesta:', data.reply ? 'tiene respuesta' : 'sin respuesta')
  } catch (error) {
    console.error('Error sending message:', error)
  } finally {
    sending.value = false
  }
}
</script>

<style scoped>
/* No additional styles needed - using Tailwind classes */
</style>
