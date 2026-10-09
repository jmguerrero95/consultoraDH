<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { fecha } from '@/composables/useFormatters';
import { useAuthStore } from '@/stores/auth';
import { a05 } from '@/services/a05';
import { ApiError } from '@/services/http';

import type { CalendarEvent, Novelty, OperationalTask, VocabularyOption } from '@/types/api';

/**
 * Operación: novedades, tareas y calendario.
 *
 * §61: un solo punto de entrada para los tres, porque son el mismo trabajo visto
 * desde tres ángulos y cinco elementos de menú para tres pantallas serían ruido.
 *
 * Una **novedad** es un hecho operational observado; una **tarea** es trabajo con
 * responsable y fecha. Son conceptos distintos y tienen pestañas distintas: mezclar
 * "le cambiaron la EPS" con "llamar al cliente" en la misma lista haría que ninguna
 * de las dos se lea bien.
 */

const auth = useAuthStore();
const pestana = ref<'novedades' | 'tareas' | 'calendario'>('novedades');

const novelties = ref<Novelty[]>([]);
const tasks = ref<OperationalTask[]>([]);
const eventos = ref<CalendarEvent[]>([]);
const categorias = ref<VocabularyOption[]>([]);
const prioridades = ref<VocabularyOption[]>([]);

const cargando = ref(true);
const errorMessage = ref<string | null>(null);
const actionMessage = ref<string | null>(null);

const nuevaNovedad = ref({
    client_id: '',
    category: '',
    title: '',
    details: '',
    occurred_on: '',
});
const nuevaTarea = ref({
    client_id: '',
    title: '',
    description: '',
    assigned_to: '',
    priority: 'normal',
    due_on: '',
    reminder_at: '',
});

const mesActual = ref(new Date());
const inicioRango = computed(() => {
    const primero = new Date(Date.UTC(mesActual.value.getUTCFullYear(), mesActual.value.getUTCMonth(), 1));

    return primero.toISOString().slice(0, 10);
});
const finRango = computed(() => {
    const ultimo = new Date(
        Date.UTC(mesActual.value.getUTCFullYear(), mesActual.value.getUTCMonth() + 1, 0),
    );

    return ultimo.toISOString().slice(0, 10);
});

async function cargarTodo(): Promise<void> {
    cargando.value = true;
    errorMessage.value = null;

    try {
        const [listaNovedades, listaTareas, eventosRango] = await Promise.all([
            a05.novelties.list({ per_page: 25 }).catch(() => ({ data: [] })),
            a05.tasks.list({ per_page: 25 }).catch(() => ({ data: [] })),
            a05.calendar.events(inicioRango.value, finRango.value).catch(() => ({ events: [] })),
        ]);

        novelties.value = listaNovedades.data ?? [];
        tasks.value = listaTareas.data ?? [];
        eventos.value = eventosRango.events ?? [];
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo cargar la operación.';
    } finally {
        cargando.value = false;
    }
}

onMounted(async () => {
    try {
        const [vocabNovedades, vocabTareas] = await Promise.all([
            a05.novelties.vocabulary(),
            a05.tasks.vocabulary(),
        ]);

        categorias.value = vocabNovedades.categories;
        prioridades.value = vocabTareas.priorities;
    } catch {
        errorMessage.value = 'No se pudo cargar el vocabulario.';
    }

    await cargarTodo();
});

async function crearNovedad(): Promise<void> {
    errorMessage.value = null;
    actionMessage.value = null;

    try {
        await a05.novelties.store({
            client_id: Number(nuevaNovedad.value.client_id),
            category: nuevaNovedad.value.category,
            title: nuevaNovedad.value.title,
            details: nuevaNovedad.value.details || null,
            occurred_on: nuevaNovedad.value.occurred_on || null,
        });

        nuevaNovedad.value = { client_id: '', category: '', title: '', details: '', occurred_on: '' };
        actionMessage.value = 'Novedad registrada.';
        await cargarTodo();
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo registrar la novedad.';
    }
}

async function resolverNovedad(novelty: Novelty): Promise<void> {
    errorMessage.value = null;

    try {
        await a05.novelties.resolve(novelty.id);
        await cargarTodo();
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo resolver.';
    }
}

async function crearTarea(): Promise<void> {
    errorMessage.value = null;
    actionMessage.value = null;

    try {
        await a05.tasks.store({
            client_id: nuevaTarea.value.client_id ? Number(nuevaTarea.value.client_id) : null,
            title: nuevaTarea.value.title,
            description: nuevaTarea.value.description || null,
            assigned_to: Number(nuevaTarea.value.assigned_to),
            priority: nuevaTarea.value.priority,
            due_on: nuevaTarea.value.due_on || null,
            reminder_at: nuevaTarea.value.reminder_at || null,
        });

        nuevaTarea.value = {
            client_id: '',
            title: '',
            description: '',
            assigned_to: '',
            priority: 'normal',
            due_on: '',
            reminder_at: '',
        };
        actionMessage.value = 'Tarea creada.';
        await cargarTodo();
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo crear la tarea.';
    }
}

async function completarTarea(task: OperationalTask): Promise<void> {
    errorMessage.value = null;

    try {
        await a05.tasks.complete(task.id);
        await cargarTodo();
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo completar.';
    }
}

function moverMes(delta: number): void {
    mesActual.value = new Date(
        Date.UTC(mesActual.value.getUTCFullYear(), mesActual.value.getUTCMonth() + delta, 1),
    );
    void cargarTodo();
}
</script>

<template>
    <div class="container-fluid py-4">
        <h1 class="h4 mb-3">Operación</h1>

        <AppAlert v-if="errorMessage" variant="danger" :message="errorMessage" class="mb-3" />
        <AppAlert v-if="actionMessage" variant="success" :message="actionMessage" class="mb-3" />

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <button
                    class="nav-link"
                    :class="{ active: pestana === 'novedades' }"
                    type="button"
                    @click="pestana = 'novedades'"
                >
                    Novedades
                </button>
            </li>
            <li class="nav-item">
                <button
                    class="nav-link"
                    :class="{ active: pestana === 'tareas' }"
                    type="button"
                    @click="pestana = 'tareas'"
                >
                    Tareas
                </button>
            </li>
            <li class="nav-item">
                <button
                    class="nav-link"
                    :class="{ active: pestana === 'calendario' }"
                    type="button"
                    @click="pestana = 'calendario'"
                >
                    Calendario
                </button>
            </li>
        </ul>

        <AppLoading v-if="cargando" />

        <div v-else class="row g-3">
            <template v-if="pestana === 'novedades'">
                <div class="col-12 col-lg-4">
                    <div v-if="auth.can('novelties.manage')" class="card">
                        <div class="card-header">
                            <h2 class="h6 mb-0">Registrar novedad</h2>
                        </div>
                        <div class="card-body d-grid gap-2">
                            <div>
                                <label class="form-label" for="cliente-novedad">Cliente (id)</label>
                                <input
                                    id="cliente-novedad"
                                    v-model="nuevaNovedad.client_id"
                                    type="number"
                                    class="form-control"
                                />
                            </div>
                            <div>
                                <label class="form-label" for="categoria">Categoría</label>
                                <select id="categoria" v-model="nuevaNovedad.category" class="form-select">
                                    <option value="">Seleccione…</option>
                                    <option v-for="c in categorias" :key="c.value" :value="c.value">
                                        {{ c.label }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="form-label" for="titulo-novedad">Título</label>
                                <input
                                    id="titulo-novedad"
                                    v-model="nuevaNovedad.title"
                                    class="form-control"
                                />
                            </div>
                            <div>
                                <label class="form-label" for="detalle-novedad">Detalle</label>
                                <textarea
                                    id="detalle-novedad"
                                    v-model="nuevaNovedad.details"
                                    class="form-control"
                                    rows="2"
                                />
                            </div>
                            <AppButton
                                variant="primary"
                                :disabled="nuevaNovedad.client_id === '' || nuevaNovedad.category === '' || nuevaNovedad.title === ''"
                                @click="crearNovedad"
                            >
                                Registrar
                            </AppButton>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-8">
                    <div v-if="novelties.length === 0" class="card">
                        <div class="card-body">
                            <AppEmptyState
                                title="Sin novedades"
                                description="No hay novedades registradas."
                            />
                        </div>
                    </div>

                    <div v-else class="card">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <caption class="visually-hidden">Novedades</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Cliente</th>
                                        <th scope="col">Categoría</th>
                                        <th scope="col">Título</th>
                                        <th scope="col">Estado</th>
                                        <th scope="col" />
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="n in novelties" :key="n.id">
                                        <td>{{ n.client_name }}</td>
                                        <td>{{ n.category_label }}</td>
                                        <td>{{ n.title }}</td>
                                        <td>
                                            <span
                                                class="badge"
                                                :class="{
                                                    'text-bg-primary': n.status === 'open',
                                                    'text-bg-info': n.status === 'in_progress',
                                                    'text-bg-success': n.status === 'resolved',
                                                    'text-bg-secondary': n.status === 'cancelled',
                                                }"
                                            >
                                                {{ n.status_label }}
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <AppButton
                                                v-if="auth.can('novelties.manage') && n.status !== 'resolved' && n.status !== 'cancelled'"
                                                variant="ghost"
                                                @click="resolverNovedad(n)"
                                            >
                                                Resolver
                                            </AppButton>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </template>

            <template v-else-if="pestana === 'tareas'">
                <div class="col-12 col-lg-4">
                    <div v-if="auth.can('tasks.manage')" class="card">
                        <div class="card-header">
                            <h2 class="h6 mb-0">Crear tarea</h2>
                        </div>
                        <div class="card-body d-grid gap-2">
                            <div>
                                <label class="form-label" for="cliente-tarea">Cliente (id, opcional)</label>
                                <input
                                    id="cliente-tarea"
                                    v-model="nuevaTarea.client_id"
                                    type="number"
                                    class="form-control"
                                />
                            </div>
                            <div>
                                <label class="form-label" for="titulo-tarea">Título</label>
                                <input id="titulo-tarea" v-model="nuevaTarea.title" class="form-control" />
                            </div>
                            <div>
                                <label class="form-label" for="descripcion-tarea">Descripción</label>
                                <textarea
                                    id="descripcion-tarea"
                                    v-model="nuevaTarea.description"
                                    class="form-control"
                                    rows="2"
                                />
                            </div>
                            <div>
                                <label class="form-label" for="responsable">Responsable (id)</label>
                                <input
                                    id="responsable"
                                    v-model="nuevaTarea.assigned_to"
                                    type="number"
                                    class="form-control"
                                />
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label" for="prioridad">Prioridad</label>
                                    <select id="prioridad" v-model="nuevaTarea.priority" class="form-select">
                                        <option v-for="p in prioridades" :key="p.value" :value="p.value">
                                            {{ p.label }}
                                        </option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="vencimiento">Vence</label>
                                    <input
                                        id="vencimiento"
                                        v-model="nuevaTarea.due_on"
                                        type="date"
                                        class="form-control"
                                    />
                                </div>
                            </div>
                            <div>
                                <label class="form-label" for="recordatorio">Recordatorio</label>
                                <input
                                    id="recordatorio"
                                    v-model="nuevaTarea.reminder_at"
                                    type="datetime-local"
                                    class="form-control"
                                />
                            </div>
                            <AppButton
                                variant="primary"
                                :disabled="nuevaTarea.title === '' || nuevaTarea.assigned_to === ''"
                                @click="crearTarea"
                            >
                                Crear tarea
                            </AppButton>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-8">
                    <div v-if="tasks.length === 0" class="card">
                        <div class="card-body">
                            <AppEmptyState
                                title="Sin tareas"
                                description="No hay tareas registradas."
                            />
                        </div>
                    </div>

                    <div v-else class="card">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <caption class="visually-hidden">Tareas</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Tarea</th>
                                        <th scope="col">Responsable</th>
                                        <th scope="col">Prioridad</th>
                                        <th scope="col">Vence</th>
                                        <th scope="col">Estado</th>
                                        <th scope="col" />
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="t in tasks" :key="t.id">
                                        <td>
                                            {{ t.title }}
                                            <span v-if="t.client_name" class="d-block small text-body-secondary">
                                                {{ t.client_name }}
                                            </span>
                                        </td>
                                        <td>{{ t.assignee_name ?? `#${t.assigned_to}` }}</td>
                                        <td>{{ t.priority_label }}</td>
                                        <td>{{ fecha(t.due_on) }}</td>
                                        <td>
                                            <span
                                                class="badge"
                                                :class="{
                                                    'text-bg-primary': t.status === 'pending',
                                                    'text-bg-info': t.status === 'in_progress',
                                                    'text-bg-success': t.status === 'done',
                                                    'text-bg-secondary': t.status === 'cancelled',
                                                }"
                                            >
                                                {{ t.status_label }}
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <AppButton
                                                v-if="auth.can('tasks.manage') && (t.status === 'pending' || t.status === 'in_progress')"
                                                variant="ghost"
                                                @click="completarTarea(t)"
                                            >
                                                Completar
                                            </AppButton>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </template>

            <template v-else>
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h2 class="h6 mb-0">
                                Calendario — {{ mesActual.toISOString().slice(0, 7) }}
                            </h2>
                            <div class="btn-group btn-group-sm">
                                <AppButton variant="ghost" @click="moverMes(-1)">
                                    <i class="bi bi-chevron-left" aria-hidden="true" />
                                    <span class="visually-hidden">Mes anterior</span>
                                </AppButton>
                                <AppButton variant="ghost" @click="moverMes(1)">
                                    <i class="bi bi-chevron-right" aria-hidden="true" />
                                    <span class="visually-hidden">Mes siguiente</span>
                                </AppButton>
                            </div>
                        </div>
                        <div class="card-body">
                            <div v-if="eventos.length === 0" class="text-body-secondary">
                                No hay eventos en este mes.
                            </div>
                            <div v-else class="list-group">
                                <div
                                    v-for="evento in eventos"
                                    :key="`${evento.type}-${evento.date}-${evento.title}`"
                                    class="list-group-item d-flex justify-content-between"
                                >
                                    <span>
                                        <span class="badge text-bg-light me-2">{{ evento.type }}</span>
                                        {{ evento.title }}
                                    </span>
                                    <span class="text-body-secondary">{{ evento.date }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
