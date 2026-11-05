<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { fecha, fechaHora } from '@/composables/useFormatters';
import { useAuthStore } from '@/stores/auth';
import { a05 } from '@/services/a05';
import { ApiError } from '@/services/http';

import type { OperationalTask } from '@/types/api';

/**
 * Detalle de una tarea (FIX-PWA-02-R2).
 *
 * Canonical route `/operacion/tareas/:id` — the shell is served by the
 * server (`operacion.tarea.detalle`, `SpaController`) and this screen is
 * what Vue renders. It fetches ONE task via `GET /api/tasks/{task}`
 * (`tasks.view`) and never via the paginated list, which is capped at
 * 25 rows and could never contain this id.
 *
 * Loading, 404/403 and retry are first-class states: a deep link from a
 * Push notification opens here even when the PWA was closed.
 */

const props = defineProps<{ id: string }>();

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();

const task = ref<OperationalTask | null>(null);
const cargando = ref(true);
const errorMessage = ref<string | null>(null);
const actionMessage = ref<string | null>(null);
const completando = ref(false);

function taskId(): number | null {
    const raw = String((route.params.id ?? props.id ?? '') as string);
    return /^\d+$/.test(raw) ? Number(raw) : null;
}

async function cargar(): Promise<void> {
    const id = taskId();
    cargando.value = true;
    errorMessage.value = null;

    if (id === null) {
        errorMessage.value = 'Identificador de tarea no válido.';
        cargando.value = false;
        return;
    }

    try {
        task.value = await a05.tasks.show(id);
    } catch (e) {
        task.value = null;
        errorMessage.value = e instanceof ApiError
            ? (e.status === 404 ? 'La tarea no existe.' : e.message)
            : 'No se pudo cargar la tarea.';
    } finally {
        cargando.value = false;
    }
}

async function completar(): Promise<void> {
    if (task.value === null) {
        return;
    }

    completando.value = true;
    errorMessage.value = null;
    actionMessage.value = null;

    try {
        task.value = await a05.tasks.complete(task.value.id);
        actionMessage.value = 'Tarea completada.';
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo completar la tarea.';
    } finally {
        completando.value = false;
    }
}

function volver(): void {
    void router.push({ name: 'operacion' });
}

onMounted(() => {
    void cargar();
});

watch(() => route.params.id, () => {
    void cargar();
});
</script>

<template>
    <div class="container-fluid py-4">
        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
            <AppButton variant="ghost" @click="volver">
                <i class="bi bi-arrow-left" aria-hidden="true" />
                Volver a Operación
            </AppButton>
        </div>

        <h1 class="h4 mb-3">Detalle de la tarea</h1>

        <AppAlert v-if="errorMessage" variant="danger" :message="errorMessage" class="mb-3" />
        <AppAlert v-if="actionMessage" variant="success" :message="actionMessage" class="mb-3" />

        <AppLoading v-if="cargando" />

        <div v-else-if="task === null" class="card">
            <div class="card-body">
                <AppEmptyState
                    title="Tarea no encontrada"
                    description="La tarea solicitada no existe o no tiene permiso para verla."
                />
                <div class="text-center mt-3">
                    <AppButton variant="primary" @click="volver">
                        Volver a Operación
                    </AppButton>
                </div>
            </div>
        </div>

        <div v-else class="card">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <h2 class="h5 mb-1">{{ task.title }}</h2>
                        <div class="d-flex gap-2 flex-wrap align-items-center">
                            <span
                                class="badge"
                                :class="{
                                    'text-bg-primary': task.status === 'pending',
                                    'text-bg-info': task.status === 'in_progress',
                                    'text-bg-success': task.status === 'done',
                                    'text-bg-secondary': task.status === 'cancelled',
                                }"
                            >
                                {{ task.status_label }}
                            </span>
                            <span class="badge text-bg-light border">
                                {{ task.priority_label }}
                            </span>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <dl class="row mb-0">
                            <dt class="col-5 text-body-secondary">Responsable</dt>
                            <dd class="col-7">{{ task.assignee_name ?? `#${task.assigned_to}` }}</dd>

                            <dt class="col-5 text-body-secondary">Cliente</dt>
                            <dd class="col-7">{{ task.client_name ?? '—' }}</dd>

                            <dt class="col-5 text-body-secondary">Vence</dt>
                            <dd class="col-7">{{ fecha(task.due_on) }}</dd>

                            <dt class="col-5 text-body-secondary">Recordatorio</dt>
                            <dd class="col-7">{{ fechaHora(task.reminder_at) }}</dd>

                            <dt class="col-5 text-body-secondary">Creada</dt>
                            <dd class="col-7">{{ fechaHora(task.created_at) }}</dd>

                            <dt v-if="task.completed_at" class="col-5 text-body-secondary">Completada</dt>
                            <dd v-if="task.completed_at" class="col-7">{{ fechaHora(task.completed_at) }}</dd>
                        </dl>
                    </div>

                    <div class="col-12 col-md-6" v-if="task.description">
                        <h3 class="h6 text-body-secondary">Descripción</h3>
                        <p class="mb-0">{{ task.description }}</p>
                    </div>
                </div>

                <div
                    v-if="auth.can('tasks.manage') && (task.status === 'pending' || task.status === 'in_progress')"
                    class="mt-4"
                >
                    <AppButton variant="primary" :busy="completando" @click="completar">
                        Completar tarea
                    </AppButton>
                </div>
            </div>
        </div>
    </div>
</template>
