<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { api } from '@/services/api';

interface Conversation {
  id: number;
  subject: string;
  status: string;
  priority: string;
  origin_channel: string;
  client: { id: number; first_names: string; last_names: string; document_number: string };
  queue: { id: number; name: string };
  assignee?: { id: number; name: string };
  last_message_at: string;
  unread_count: number;
}

interface InboxFilters {
  queue?: number;
  assigned_to_me?: boolean;
  unassigned?: boolean;
  assignee?: number;
  status?: string;
  priority?: string;
  sla_state?: string;
  unread?: boolean;
  client_search?: string;
  page: number;
  per_page: number;
}

const router = useRouter();
const auth = useAuthStore();

const conversations = ref<Conversation[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 });

const filters = ref<InboxFilters>({
  queue: undefined,
  assigned_to_me: false,
  unassigned: false,
  assignee: undefined,
  status: undefined,
  priority: undefined,
  sla_state: undefined,
  unread: false,
  client_search: '',
  page: 1,
  per_page: 25,
});

const queues = ref<{ id: number; name: string }[]>([]);
const assignees = ref<{ id: number; name: string }[]>([]);

const statusOptions = [
  { value: 'open', label: 'Abierta' },
  { value: 'waiting_staff', label: 'Esperando staff' },
  { value: 'waiting_client', label: 'Esperando cliente' },
  { value: 'resolved', label: 'Resuelta' },
  { value: 'closed', label: 'Cerrada' },
];

const priorityOptions = [
  { value: 'normal', label: 'Normal' },
  { value: 'high', label: 'Alta' },
  { value: 'urgent', label: 'Urgente' },
];

const slaStateOptions = [
  { value: 'ok', label: 'OK' },
  { value: 'warning', label: 'Advertencia' },
  { value: 'breach', label: 'Incumplimiento' },
];

async function loadQueues() {
  try {
    const response = await api.get('/support/queues');
    queues.value = response.data.data;
  } catch (e) {
    console.error('Error loading queues:', e);
  }
}

async function loadAssignees() {
  try {
    const response = await api.get('/users', { params: { account_type: 'staff', status: 'active' } });
    assignees.value = response.data.data.map((u: any) => ({ id: u.id, name: u.name }));
  } catch (e) {
    console.error('Error loading assignees:', e);
  }
}

async function loadConversations() {
  loading.value = true;
  error.value = null;

  try {
    const params: Record<string, any> = {
      page: filters.value.page,
      per_page: filters.value.per_page,
    };

    if (filters.value.queue) params.queue = filters.value.queue;
    if (filters.value.assigned_to_me) params.assigned_to_me = true;
    if (filters.value.unassigned) params.unassigned = true;
    if (filters.value.assignee) params.assignee = filters.value.assignee;
    if (filters.value.status) params.status = filters.value.status;
    if (filters.value.priority) params.priority = filters.value.priority;
    if (filters.value.unread) params.unread = true;
    if (filters.value.client_search) params.client_search = filters.value.client_search;

    const response = await api.get('/support/inbox', { params });
    conversations.value = response.data.data;
    pagination.value = {
      current_page: response.data.current_page,
      last_page: response.data.last_page,
      total: response.data.total,
      per_page: response.data.per_page,
    };
  } catch (e) {
    error.value = 'Error al cargar conversaciones';
    console.error(e);
  } finally {
    loading.value = false;
  }
}

function onPageChange(page: number) {
  filters.value.page = page;
  loadConversations();
}

function onFilterChange() {
  filters.value.page = 1;
  loadConversations();
}

function openConversation(conversation: Conversation) {
  router.push(`/soporte/${conversation.id}`);
}

const statusBadgeClass = (status: string) => {
  const classes: Record<string, string> = {
    open: 'bg-gray-100 text-gray-800',
    waiting_staff: 'bg-yellow-100 text-yellow-800',
    waiting_client: 'bg-blue-100 text-blue-800',
    resolved: 'bg-green-100 text-green-800',
    closed: 'bg-gray-100 text-gray-600',
  };
  return classes[status] || 'bg-gray-100 text-gray-800';
};

const priorityBadgeClass = (priority: string) => {
  const classes: Record<string, string> = {
    normal: 'bg-gray-100 text-gray-800',
    high: 'bg-orange-100 text-orange-800',
    urgent: 'bg-red-100 text-red-800',
  };
  return classes[priority] || 'bg-gray-100 text-gray-800';
}

const statusLabel = (status: string) => {
  const labels: Record<string, string> = {
    open: 'Abierta',
    waiting_staff: 'Esperando staff',
    waiting_client: 'Esperando cliente',
    resolved: 'Resuelta',
    closed: 'Cerrada',
  };
  return labels[status] || status;
};

const priorityLabel = (priority: string) => {
  const labels: Record<string, string> = {
    normal: 'Normal',
    high: 'Alta',
    urgent: 'Urgente',
  };
  return labels[priority] || priority;
};

const formatRelativeTime = (dateString: string) => {
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
  return date.toLocaleDateString('es-ES');
};

const paginationRange = computed(() => {
  const current = pagination.value.current_page;
  const last = pagination.value.last_page;
  const range: number[] = [];

  let start = Math.max(1, pagination.value.current_page - 2);
  let end = Math.min(last, pagination.value.current_page + 2);

  if (end - start < 4) {
    if (start === 1) end = Math.min(5, last);
    if (end === last) start = Math.max(1, last - 4);
  }

  for (let i = start; i <= end; i++) {
    range.push(i);
  }

  return range;
});

async function loadQueues() {
  try {
    const response = await api.get('/support/queues');
    queues.value = response.data.data;
  } catch (e) {
    console.error('Error loading queues:', e);
  }
}

async function loadAssignees() {
  try {
    const response = await api.get('/users', { params: { account_type: 'staff', status: 'active' } });
    assignees.value = response.data.data.map((u: any) => ({ id: u.id, name: u.name }));
  } catch (e) {
    console.error('Error loading assignees:', e);
  }
}

async function loadConversations() {
  loading.value = true;
  error.value = null;

  try {
    const params: Record<string, any> = {
      page: filters.value.page,
      per_page: filters.value.per_page,
    };

    if (filters.value.queue) params.queue = filters.value.queue;
    if (filters.value.assigned_to_me) params.assigned_to_me = true;
    if (filters.value.unassigned) params.unassigned = true;
    if (filters.value.assignee) params.assignee = filters.value.assignee;
    if (filters.value.status) params.status = filters.value.status;
    if (filters.value.priority) params.priority = filters.value.priority;
    if (filters.value.unread) params.unread = true;
    if (filters.value.client_search) params.client_search = filters.value.client_search;

    const response = await api.get('/support/inbox', { params });
    conversations.value = response.data.data;
    pagination.value = {
      current_page: response.data.current_page,
      last_page: response.data.last_page,
      total: response.data.total,
      per_page: response.data.per_page,
    };
  } catch (e) {
    error.value = 'Error al cargar conversaciones';
    console.error(e);
  } finally {
    loading.value = false;
  }
}

function onPageChange(page: number) {
  filters.value.page = page;
  loadConversations();
}

function onFilterChange() {
  filters.value.page = 1;
  loadConversations();
}

function openConversation(conversation: Conversation) {
  router.push(`/soporte/${conversation.id}`);
}

const statusBadgeClass = (status: string) => {
  const classes: Record<string, string> = {
    open: 'bg-gray-100 text-gray-800',
    waiting_staff: 'bg-yellow-100 text-yellow-800',
    waiting_client: 'bg-blue-100 text-blue-800',
    resolved: 'bg-green-100 text-green-800',
    closed: 'bg-gray-100 text-gray-600',
  };
  return classes[status] || 'bg-gray-100 text-gray-800';
}

const priorityBadgeClass = (priority: string) => {
  const classes: Record<string, string> = {
    normal: 'bg-gray-100 text-gray-800',
    high: 'bg-orange-100 text-orange-800',
    urgent: 'bg-red-100 text-red-800',
  };
  return classes[priority] || 'bg-gray-100 text-gray-800';
}

const formatRelativeTime = (dateString: string) => {
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
  return date.toLocaleDateString('es-ES');
}

const paginationRange = computed(() => {
  const current = pagination.value.current_page;
  const last = pagination.value.last_page;
  const range: number[] = [];

  let start = Math.max(1, pagination.value.current_page - 2);
  let end = Math.min(last, pagination.value.current_page + 2);

  if (end - start < 4) {
    if (start === 1) end = Math.min(5, last);
    if (end === last) start = Math.max(1, last - 4);
  }

  for (let i = start; i <= end; i++) {
    range.push(i);
  }

  return range;
}

const router = useRouter();
const auth = useAuthStore();

const conversations = ref<Conversation[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 });

const filters = ref<InboxFilters>({
  queue: undefined,
  assigned_to_me: false,
  unassigned: false,
  assignee: undefined,
  status: undefined,
  priority: undefined,
  sla_state: undefined,
  unread: false,
  client_search: '',
  page: 1,
  per_page: 25,
});

const queues = ref<{ id: number; name: string }[]>([]);
const assignees = ref<{ id: number; name: string }[]>([]);

const statusOptions = [
  { value: 'open', label: 'Abierta' },
  { value: 'waiting_staff', label: 'Esperando staff' },
  { value: 'waiting_client', label: 'Esperando cliente' },
  { value: 'resolved', label: 'Resuelta' },
  { value: 'closed', label: 'Cerrada' },
];

const priorityOptions = [
  { value: 'normal', label: 'Normal' },
  { value: 'high', label: 'Alta' },
  { value: 'urgent', label: 'Urgente' },
];

const slaStateOptions = [
  { value: 'ok', label: 'OK' },
  { value: 'warning', label: 'Advertencia' },
  { value: 'breach', label: 'Incumplimiento' },
];

const loading = ref(false);
const error = ref<string | null>(null);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 });

const filters = ref<InboxFilters>({
  queue: undefined,
  assigned_to_me: false,
  unassigned: false,
  assignee: undefined,
  status: undefined,
  priority: undefined,
  sla_state: undefined,
  unread: false,
  client_search: '',
  page: 1,
  per_page: 25,
});

const queues = ref<{ id: number; name: string }[]>([]);
const assignees = ref<{ id: number; name: string }[]>([]);

const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 });

async function loadQueues() {
  try {
    const response = await api.get('/support/queues');
    queues.value = response.data.data;
  } catch (e) {
    console.error('Error loading queues:', e);
  }
}

async function loadAssignees() {
  try {
    const response = await api.get('/users', { params: { account_type: 'staff', status: 'active' } });
    assignees.value = response.data.data.map((u: any) => ({ id: u.id, name: u.name }));
  } catch (e) {
    console.error('Error loading assignees:', e);
  }
}

async function loadConversations() {
  loading.value = true;
  error.value = null;

  try {
    const params: Record<string, any> = {
      page: filters.value.page,
      per_page: filters.value.per_page,
    };

    if (filters.value.queue) params.queue = filters.value.queue;
    if (filters.value.assigned_to_me) params.assigned_to_me = true;
    if (filters.value.unassigned) params.unassigned = true;
    if (filters.value.assignee) params.assignee = filters.value.assignee;
    if (filters.value.status) params.status = filters.value.status;
    if (filters.value.priority) params.priority = filters.value.priority;
    if (filters.value.unread) params.unread = true;
    if (filters.value.client_search) params.client_search = filters.value.client_search;

    const response = await api.get('/support/inbox', { params });
    conversations.value = response.data.data;
    pagination.value = {
      current_page: response.data.current_page,
      last_page: response.data.last_page,
      total: response.data.total,
      per_page: response.data.per_page,
    };
  } catch (e) {
    error.value = 'Error al cargar conversaciones';
    console.error(e);
  } finally {
    loading.value = false;
  }
}

function onPageChange(page: number) {
  filters.value.page = page;
  loadConversations();
}

function onFilterChange() {
  filters.value.page = 1;
  loadConversations();
}

function openConversation(conversation: Conversation) {
  router.push(`/soporte/${conversation.id}`);
}
</script>

<template>
  <div class="support-inbox">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h1 class="h3 mb-0">Bandeja de Soporte</h1>
      <button class="btn btn-primary" @click="router.push('/soporte/nueva')">
        <i class="bi bi-plus-lg me-1"></i> Nueva conversación
      </button>
    </div>

    <!-- Filtros -->
    <div class="card mb-4">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Cola</label>
            <select class="form-select" v-model="filters.queue" @change="onFilterChange">
              <option value="">Todas</option>
              <option v-for="q in queues" :key="q.id" :value="q.id">{{ q.name }}</option>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Asignado</label>
            <select class="form-select" v-model="filters.assignee" @change="onFilterChange">
              <option value="">Todos</option>
              <option v-for="a in assignees" :key="a.id" :value="a.id">{{ a.name }}</option>
            </select>
          </div>

          <div class="col-md-2">
            <label class="form-label">Estado</label>
            <select class="form-select" v-model="filters.status" @change="onFilterChange">
              <option value="">Todos</option>
              <option v-for="s in statusOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
          </div>

          <div class="col-md-2">
            <label class="form-label">Prioridad</label>
            <select class="form-select" v-model="filters.priority" @change="onFilterChange">
              <option value="">Todas</option>
              <option v-for="p in priorityOptions" :key="p.value" :value="p.value">{{ p.label }}</option>
            </select>
          </div>

          <div class="col-md-2">
            <div class="form-check mt-4">
              <input class="form-check-input" type="checkbox" v-model="filters.assigned_to_me" id="filter-assigned" @change="onFilterChange">
              <label class="form-check-label" for="filter-assigned">Mis asignadas</label>
            </div>
          </div>

          <div class="col-md-2">
            <div class="form-check mt-4">
              <input class="form-check-input" type="checkbox" v-model="filters.unassigned" id="filter-unassigned" @change="onFilterChange">
              <label class="form-check-label" for="filter-unassigned">Sin asignar</label>
            </div>
          </div>

          <div class="col-md-2">
            <div class="form-check mt-4">
              <input class="form-check-input" type="checkbox" v-model="filters.unread" id="filter-unread" @change="onFilterChange">
              <label class="form-check-label" for="filter-unread">No leídas</label>
            </div>
          </div>

          <div class="col-md-12">
            <div class="input-group">
              <input type="text" class="form-control" placeholder="Buscar cliente..." v-model="filters.client_search" @input.debounce.300="onFilterChange">
              <button class="btn btn-outline-secondary" type="button" @click="filters.client_search = ''; onFilterChange()" v-if="filters.client_search">
                <i class="bi bi-x-lg"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Lista de conversaciones -->
    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary" role="status">
        <span class="visually-hidden">Cargando...</span>
      </div>
    </div>

    <div v-else-if="error" class="alert alert-danger">
      {{ error }}
    </div>

    <div v-else-if="conversations.length === 0" class="text-center py-5">
      <i class="bi bi-inbox display-1 text-muted"></i>
      <p class="text-muted mt-3">No hay conversaciones que coincidan con los filtros</p>
    </div>

    <div v-else class="table-responsive">
      <table class="table table-hover align-middle">
        <thead class="table-light">
          <tr>
            <th>Asunto / Cliente</th>
            <th class="d-none d-md-table-cell">Cola</th>
            <th class="d-none d-lg-table-cell">Asignado</th>
            <th class="text-center" style="width: 100px;">Estado</th>
            <th class="text-center" style="width: 100px;">Prioridad</th>
            <th class="text-center" style="width: 120px;">Último mensaje</th>
            <th class="text-center" style="width: 80px;">No leídos</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in conversations" :key="c.id" @click="openConversation(c)" class="cursor-pointer" :class="{ 'table-primary': c.unread_count > 0 }">
            <td>
              <div>
                <strong>{{ c.subject }}</strong>
                <div class="text-muted small">{{ c.client.first_names }} {{ c.client.last_names }} ({{ c.client.document_number }})</div>
              </div>
            </td>
            <td class="d-none d-md-table-cell">{{ c.queue?.name }}</td>
            <td class="d-none d-lg-table-cell">
              <span v-if="c.assignee">{{ c.assignee.name }}</span>
              <span v-else class="text-muted">Sin asignar</span>
            </td>
            <td class="text-center">
              <span :class="statusBadgeClass(c.status)" class="badge px-2 py-1">
                {{ statusLabel(c.status) }}
              </span>
            </td>
            <td class="text-center">
              <span :class="priorityBadgeClass(c.priority)" class="badge px-2 py-1">
                {{ priorityLabel(c.priority) }}
              </span>
            </td>
            <td class="text-center text-muted small">
              {{ formatRelativeTime(c.last_message_at) }}
            </td>
            <td class="text-center">
              <span v-if="c.unread_count > 0" class="badge bg-primary rounded-pill">{{ c.unread_count }}</span>
              <span v-else class="text-muted">0</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <nav v-if="pagination.last_page > 1" aria-label="Paginación">
      <ul class="pagination justify-content-center">
        <li class="page-item" :class="{ disabled: pagination.current_page === 1 }">
          <button class="page-link" @click="onPageChange(pagination.current_page - 1)" :disabled="pagination.current_page === 1">&laquo;</button>
        </li>
        <li v-for="p in paginationRange" :key="p" :class="{ 'page-item': true, active: pagination.current_page === p }">
          <button class="page-link" @click="onPageChange(p)">{{ p }}</button>
        </li>
        <li class="page-item" :class="{ disabled: pagination.current_page === pagination.last_page }">
          <button class="page-link" @click="onPageChange(pagination.current_page + 1)" :disabled="pagination.current_page === pagination.last_page">&raquo;</button>
        </li>
      </ul>
    </nav>
  </div>
</template>

<style scoped>
.cursor-pointer:hover {
  background-color: rgba(0, 0, 0, 0.03);
}
</style>