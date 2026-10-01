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

/**
 * Create or edit a company.
 *
 * There are deliberately no fields for a customer's portal credentials. A
 * consulting firm administering payroll needs a company's legal identity and a way
 * to reach it; storing somebody else's login would create an obligation this
 * application has no way to meet, and the column does not exist.
 */

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const editingId = computed(() => {
    const raw = route.params.id;

    return raw === undefined ? null : Number(raw);
});

const isEditing = computed(() => editingId.value !== null);
const canSubmit = computed(() => (isEditing.value ? auth.can('companies.update') : auth.can('companies.create')));

const form = reactive({
    legal_name: '',
    trade_name: '',
    tax_id: '',
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

onMounted(async () => {
    if (editingId.value === null) {
        return;
    }

    loading.value = true;

    try {
        const payload = await businessApi.companies.show(editingId.value);

        form.legal_name = payload.company.legal_name;
        form.trade_name = payload.company.trade_name ?? '';
        form.tax_id = payload.company.tax_id ?? '';
        form.email = payload.company.email ?? '';
        form.phone = payload.company.phone ?? '';
        form.address = payload.company.address ?? '';
        form.city = payload.company.city ?? '';
        form.department = payload.company.department ?? '';
    } catch (cause) {
        formError.value = cause instanceof ApiError ? cause.message : 'No fue posible cargar la empresa.';
    } finally {
        loading.value = false;
    }
});

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
        legal_name: form.legal_name,
        trade_name: form.trade_name === '' ? null : form.trade_name,
        tax_id: form.tax_id === '' ? null : form.tax_id,
        email: form.email === '' ? null : form.email,
        phone: form.phone === '' ? null : form.phone,
        address: form.address === '' ? null : form.address,
        city: form.city === '' ? null : form.city,
        department: form.department === '' ? null : form.department,
    };

    try {
        const result = isEditing.value
            ? await businessApi.companies.update(editingId.value as number, payload)
            : await businessApi.companies.create(payload);

        await router.push({ name: 'companies.show', params: { id: result.company.id } });
    } catch (cause) {
        if (cause instanceof ApiError) {
            applyErrors(cause.errors);
            formError.value = cause.message;
        } else {
            formError.value = 'No fue posible guardar la empresa.';
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
                <h1 class="cdh-page-header__title">
                    {{ isEditing ? 'Editar empresa' : 'Nueva empresa' }}
                </h1>
                <p class="cdh-page-header__subtitle">
                    No se guardan credenciales de acceso a portales de terceros.
                </p>
            </div>
        </header>

        <AppLoading v-if="loading" label="Cargando empresa" />

        <AppAlert v-else-if="formError" variant="danger" :title="formError" class="mb-4" />

        <form v-else class="cdh-stack-4" novalidate @submit.prevent="submit">
            <fieldset class="cdh-card">
                <legend class="cdh-card__title">Identificación</legend>
                <div class="cdh-card__body cdh-grid-2">
                    <AppFormField
                        v-model="form.legal_name"
                        label="Razón social"
                        name="legal_name"
                        required
                        :error="errors.legal_name ?? null"
                    />
                    <AppFormField
                        v-model="form.trade_name"
                        label="Nombre comercial"
                        name="trade_name"
                        :error="errors.trade_name ?? null"
                    />
                    <AppFormField
                        v-model="form.tax_id"
                        label="NIT"
                        name="tax_id"
                        placeholder="900.123.456-1"
                        :error="errors.tax_id ?? null"
                        hint="Se puede escribir con puntos; se guarda sin ellos."
                    />
                </div>
            </fieldset>

            <fieldset class="cdh-card">
                <legend class="cdh-card__title">Contacto</legend>
                <div class="cdh-card__body cdh-grid-2">
                    <AppFormField
                        v-model="form.email"
                        label="Correo electrónico"
                        name="email"
                        type="email"
                        inputmode="email"
                        :error="errors.email ?? null"
                    />
                    <AppFormField
                        v-model="form.phone"
                        label="Teléfono"
                        name="phone"
                        type="tel"
                        inputmode="tel"
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

            <fieldset class="cdh-card">
                <legend class="cdh-card__title">Estado</legend>
                <div class="cdh-card__body">
                    <p class="cdh-text-sm cdh-text-muted mb-0">
                        {{
                            isEditing
                                ? 'El estado se cambia desde la ficha de la empresa. Desactivarla no es posible'
                                      + ' mientras tenga clientes con relación abierta.'
                                : 'La empresa queda activa al crearse.'
                        }}
                    </p>
                </div>
            </fieldset>

            <div v-if="canSubmit" class="d-flex gap-2">
                <AppButton type="submit" :busy="submitting" icon="bi-check-lg">
                    {{ isEditing ? 'Guardar cambios' : 'Crear empresa' }}
                </AppButton>
                <AppButton variant="ghost" @click="router.back()">Cancelar</AppButton>
            </div>

            <AppAlert v-else variant="info" title="No tiene permiso para crear ni modificar empresas." />
        </form>
    </section>
</template>
