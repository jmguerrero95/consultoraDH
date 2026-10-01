<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppFormField from '@/components/ui/AppFormField.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';

import type { DocumentType } from '@/types/api';

/**
 * Create or edit a client.
 *
 * One component serves both, because the fields are the same and the difference
 * is only which record is loaded and whether the identity block is editable. Two
 * components would be two places for the Spanish validation messages to drift.
 *
 * The identity block is read only when editing, and that is a domain decision
 * rather than a UI preference: the document is what makes the record unique, and
 * changing it is a different decision with its own checks.
 */

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const DOCUMENT_TYPES: { value: DocumentType; label: string }[] = [
    { value: 'CC', label: 'Cédula de ciudadanía' },
    { value: 'CE', label: 'Cédula de extranjería' },
    { value: 'TI', label: 'Tarjeta de identidad' },
    { value: 'PPT', label: 'Permiso por Protección Temporal' },
    { value: 'PASSPORT', label: 'Pasaporte' },
    { value: 'OTHER', label: 'Otro' },
];

const editingId = computed(() => {
    const raw = route.params.id;

    return raw === undefined ? null : Number(raw);
});

const isEditing = computed(() => editingId.value !== null);
const canSubmit = computed(() => (isEditing.value ? auth.can('clients.update') : auth.can('clients.create')));

const form = reactive({
    document_type: 'CC' as DocumentType,
    document_number: '',
    first_names: '',
    last_names: '',
    email: '',
    phone: '',
    address: '',
    city: '',
    department: '',
});

const errors = reactive<Record<string, string | null>>({});
const loading = ref(false);
const submitting = ref(false);
const formError = ref<string | null>(null);

const heading = computed(() => (isEditing.value ? 'Editar cliente' : 'Nuevo cliente'));

onMounted(async () => {
    if (editingId.value === null) {
        return;
    }

    loading.value = true;

    try {
        const payload = await businessApi.clients.show(editingId.value);

        form.document_type = payload.client.document_type;
        form.document_number = payload.client.document_number;
        form.first_names = payload.client.first_names;
        form.last_names = payload.client.last_names;
        form.email = payload.client.email ?? '';
        form.phone = payload.client.phone ?? '';
        form.address = payload.client.address ?? '';
        form.city = payload.client.city ?? '';
        form.department = payload.client.department ?? '';
    } catch (cause) {
        formError.value = cause instanceof ApiError ? cause.message : 'No fue posible cargar el cliente.';
    } finally {
        loading.value = false;
    }
});

/**
 * Validation messages keyed by field, so the form shows what the server said
 * rather than a generic failure.
 */
function applyErrors(fieldErrors: Record<string, string[]> | undefined): void {
    for (const key of Object.keys(errors)) {
        delete errors[key];
    }

    for (const [field, messages] of Object.entries(fieldErrors ?? {})) {
        errors[field] = messages[0] ?? null;
    }
}

async function submit(): Promise<void> {
    if (submitting.value) {
        return;
    }

    submitting.value = true;
    formError.value = null;
    applyErrors(undefined);

    const payload: Record<string, string | null> = {
        first_names: form.first_names,
        last_names: form.last_names,
        email: form.email === '' ? null : form.email,
        phone: form.phone === '' ? null : form.phone,
        address: form.address === '' ? null : form.address,
        city: form.city === '' ? null : form.city,
        department: form.department === '' ? null : form.department,
    };

    if (!isEditing.value) {
        payload.document_type = form.document_type;
        payload.document_number = form.document_number;
    }

    try {
        const result = isEditing.value
            ? await businessApi.clients.update(editingId.value as number, payload)
            : await businessApi.clients.create(payload);

        await router.push({
            name: 'clients.show',
            params: { id: result.client.id },
        });
    } catch (cause) {
        if (cause instanceof ApiError) {
            applyErrors(cause.errors);

            formError.value = cause.message;
        } else {
            formError.value = 'No fue posible guardar el cliente.';
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <section class="cdh-content">
        <header class="cdh-page-header">
            <div>
                <h1 class="cdh-page-header__title">{{ heading }}</h1>
                <p class="cdh-page-header__subtitle">
                    {{
                        isEditing
                            ? 'Los datos de contacto pueden modificarse en cualquier momento.'
                            : 'Una persona se registra una sola vez; las relaciones y afiliaciones se'
                                  + ' registran aparte.'
                    }}
                </p>
            </div>
        </header>

        <AppLoading v-if="loading" label="Cargando cliente" />

        <AppAlert v-else-if="formError" variant="danger" :title="formError" class="mb-4" />

        <form v-else class="cdh-stack-4" novalidate @submit.prevent="submit">
            <!-- Identification -->
            <fieldset class="cdh-card">
                <legend class="cdh-card__title">Identificación</legend>
                <div class="cdh-card__body cdh-grid-2">
                    <AppFormField
                        v-model="form.document_type"
                        label="Tipo de documento"
                        name="document_type"
                        required
                        :options="DOCUMENT_TYPES"
                        :disabled="isEditing"
                        :error="errors.document_type ?? null"
                        hint="El tipo y el número identifican a una sola persona."
                    />

                    <AppFormField
                        v-model="form.document_number"
                        label="Número"
                        name="document_number"
                        required
                        :disabled="isEditing"
                        :error="errors.document_number ?? null"
                        hint="Se puede escribir con puntos o guiones; se guarda sin separadores."
                    />
                </div>
            </fieldset>

            <!-- Contact -->
            <fieldset class="cdh-card">
                <legend class="cdh-card__title">Contacto</legend>
                <div class="cdh-card__body cdh-grid-2">
                    <AppFormField
                        v-model="form.first_names"
                        label="Nombres"
                        name="first_names"
                        required
                        :error="errors.first_names ?? null"
                    />
                    <AppFormField
                        v-model="form.last_names"
                        label="Apellidos"
                        name="last_names"
                        required
                        :error="errors.last_names ?? null"
                    />
                    <AppFormField
                        v-model="form.email"
                        label="Correo electrónico"
                        name="email"
                        type="email"
                        inputmode="email"
                        autocomplete="email"
                        :error="errors.email ?? null"
                    />
                    <AppFormField
                        v-model="form.phone"
                        label="Teléfono"
                        name="phone"
                        type="tel"
                        inputmode="tel"
                        autocomplete="tel"
                        :error="errors.phone ?? null"
                    />
                    <AppFormField
                        v-model="form.address"
                        label="Dirección"
                        name="address"
                        :error="errors.address ?? null"
                    />
                    <AppFormField
                        v-model="form.city"
                        label="Ciudad"
                        name="city"
                        :error="errors.city ?? null"
                    />
                    <AppFormField
                        v-model="form.department"
                        label="Departamento"
                        name="department"
                        :error="errors.department ?? null"
                    />
                </div>
            </fieldset>

            <!-- Status -->
            <fieldset class="cdh-card">
                <legend class="cdh-card__title">Estado</legend>
                <div class="cdh-card__body">
                    <p class="cdh-text-sm cdh-text-muted mb-0">
                        {{
                            isEditing
                                ? 'El estado se cambia desde la ficha del cliente, porque desactivarlo puede'
                                      + ' requerir cerrar sus relaciones abiertas.'
                                : 'El cliente queda activo al crearse. Después podrá vincularlo a empresas y'
                                      + ' afiliaciones.'
                        }}
                    </p>
                </div>
            </fieldset>

            <div v-if="canSubmit" class="cdh-stack-4 d-flex gap-2">
                <AppButton type="submit" :busy="submitting" icon="bi-check-lg">
                    {{ isEditing ? 'Guardar cambios' : 'Crear cliente' }}
                </AppButton>
                <AppButton variant="ghost" @click="router.back()">Cancelar</AppButton>
            </div>

            <AppAlert
                v-else
                variant="info"
                title="No tiene permiso para crear ni modificar clientes."
            />
        </form>
    </section>
</template>