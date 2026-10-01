<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppFormField from '@/components/ui/AppFormField.vue';
import AppPasswordField from '@/components/ui/AppPasswordField.vue';
import { api } from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';

/**
 * Self service profile management.
 *
 * Three independent concerns, three separate forms, because they have different
 * risk profiles: the display name is harmless, while the address and the
 * password both require the current password and are audited.
 */
const auth = useAuthStore();

// --- Display name -----------------------------------------------------------
const name = reactive({ value: '', error: null as string | null });
const savingName = ref(false);
const nameFeedback = ref<{ variant: 'success' | 'danger'; text: string } | null>(null);

// --- Email address ----------------------------------------------------------
const email = reactive({
    value: '',
    currentPassword: '',
    currentPasswordVisible: false,
    errors: {} as Record<string, string | null>,
});
const savingEmail = ref(false);
const emailFeedback = ref<{ variant: 'success' | 'danger'; text: string } | null>(null);

// --- Password ---------------------------------------------------------------
const password = reactive({
    value: '',
    confirmation: '',
    visible: false,
    confirmationVisible: false,
    currentPassword: '',
    currentVisible: false,
    errors: {} as Record<string, string | null>,
});
const savingPassword = ref(false);
const passwordFeedback = ref<{ variant: 'success' | 'danger'; text: string } | null>(null);

/**
 * The API returns an ISO 8601 timestamp. It is presented in the same short
 * Spanish format the dashboard uses, so the two screens agree.
 */
function lastLogin(): string {
    const value = auth.user?.last_login_at;

    if (value === null || value === undefined) {
        return 'Primer ingreso';
    }

    return new Intl.DateTimeFormat('es-CO', {
        dateStyle: 'short',
        timeStyle: 'short',
        timeZone: 'America/Bogota',
    }).format(new Date(value));
}

async function submitName(): Promise<void> {
    savingName.value = true;
    nameFeedback.value = null;
    name.error = null;

    try {
        const response = await api.profile.update({ name: name.value });

        auth.setUser(response.user);

        nameFeedback.value = { variant: 'success', text: 'Sus datos fueron actualizados.' };
    } catch (caught) {
        name.error = caught instanceof ApiError ? caught.fieldError('name') : null;
        nameFeedback.value = {
            variant: 'danger',
            text: caught instanceof ApiError ? caught.message : 'No fue posible guardar los cambios.',
        };
    } finally {
        savingName.value = false;
    }
}

async function submitEmail(): Promise<void> {
    savingEmail.value = true;
    emailFeedback.value = null;
    email.errors = {};

    try {
        const response = await api.profile.updateEmail({
            email: email.value,
            current_password: email.currentPassword,
        });

        auth.setUser(response.user);

        email.value = response.user.email;
        email.currentPassword = '';

        emailFeedback.value = { variant: 'success', text: response.message };
    } catch (caught) {
        if (caught instanceof ApiError) {
            email.errors = {
                email: caught.fieldError('email'),
                current_password: caught.fieldError('current_password'),
            };
            emailFeedback.value = { variant: 'danger', text: caught.message };
        } else {
            emailFeedback.value = {
                variant: 'danger',
                text: 'No fue posible actualizar el correo electrónico.',
            };
        }
    } finally {
        savingEmail.value = false;
    }
}

async function submitPassword(): Promise<void> {
    savingPassword.value = true;
    passwordFeedback.value = null;
    password.errors = {};

    try {
        const response = await api.profile.updatePassword({
            current_password: password.currentPassword,
            password: password.value,
            password_confirmation: password.confirmation,
        });

        password.value = '';
        password.confirmation = '';
        password.currentPassword = '';

        passwordFeedback.value = { variant: 'success', text: response.message };
    } catch (caught) {
        if (caught instanceof ApiError) {
            password.errors = {
                password: caught.fieldError('password'),
                current_password: caught.fieldError('current_password'),
                password_confirmation: caught.fieldError('password_confirmation'),
            };
            passwordFeedback.value = { variant: 'danger', text: caught.message };
        } else {
            passwordFeedback.value = {
                variant: 'danger',
                text: 'No fue posible actualizar la contraseña.',
            };
        }
    } finally {
        savingPassword.value = false;
    }
}

onMounted(() => {
    name.value = auth.user?.name ?? '';
    email.value = auth.user?.email ?? '';
});
</script>

<template>
    <div>
        <div class="cdh-page-header">
            <div>
                <h1 class="cdh-page-header__title">Mi perfil</h1>
                <p class="cdh-page-header__subtitle">
                    Actualice sus datos de acceso. Los cambios quedan registrados en la
                    auditoría.
                </p>
            </div>
        </div>

        <div class="cdh-grid-2">
            <!-- Account summary, read only. -->
            <section class="cdh-card" aria-labelledby="account-heading">
                <div class="cdh-card__header">
                    <h2 id="account-heading" class="cdh-card__title">Cuenta</h2>
                </div>

                <div class="cdh-card__body">
                    <dl class="cdh-status-list mb-0">
                        <div class="cdh-status-row">
                            <dt class="cdh-status-row__label">Correo electrónico</dt>
                            <dd class="cdh-status-row__value mb-0">{{ auth.user?.email }}</dd>
                        </div>

                        <div class="cdh-status-row">
                            <dt class="cdh-status-row__label">Rol principal</dt>
                            <dd class="cdh-status-row__value mb-0">
                                {{ auth.user?.primary_role ?? 'Sin rol' }}
                            </dd>
                        </div>

                        <div v-if="(auth.user?.roles.length ?? 0) > 1" class="cdh-status-row">
                            <dt class="cdh-status-row__label">Roles</dt>
                            <dd class="cdh-status-row__value mb-0">
                                <span
                                    v-for="role in auth.user?.roles"
                                    :key="role"
                                    class="cdh-badge cdh-badge--info me-1"
                                >
                                    {{ role }}
                                </span>
                            </dd>
                        </div>

                        <div class="cdh-status-row">
                            <dt class="cdh-status-row__label">Estado</dt>
                            <dd class="cdh-status-row__value mb-0">
                                <span class="cdh-badge cdh-badge--success">
                                    {{ auth.user?.status_label }}
                                </span>
                            </dd>
                        </div>

                        <div class="cdh-status-row">
                            <dt class="cdh-status-row__label">Último ingreso</dt>
                            <dd class="cdh-status-row__value mb-0">{{ lastLogin() }}</dd>
                        </div>
                    </dl>
                </div>
            </section>

            <div class="cdh-stack-5">
                <!-- Display name. -->
                <section class="cdh-card" aria-labelledby="name-heading">
                    <div class="cdh-card__header">
                        <h2 id="name-heading" class="cdh-card__title">Nombre visible</h2>
                    </div>

                    <div class="cdh-card__body">
                        <AppAlert
                            v-if="nameFeedback"
                            :variant="nameFeedback.variant"
                            class="mb-3"
                        >
                            {{ nameFeedback.text }}
                        </AppAlert>

                        <form novalidate @submit.prevent="submitName">
                            <div class="cdh-stack-4">
                                <AppFormField
                                    v-model="name.value"
                                    name="name"
                                    label="Nombre completo"
                                    autocomplete="name"
                                    required
                                    :disabled="savingName"
                                    :error="name.error"
                                    hint="Es el nombre que los demás usuarios verán en la plataforma."
                                />

                                <AppButton type="submit" :busy="savingName">
                                    Guardar nombre
                                </AppButton>
                            </div>
                        </form>
                    </div>
                </section>

                <!-- Email address: requires the current password. -->
                <section class="cdh-card" aria-labelledby="email-heading">
                    <div class="cdh-card__header">
                        <h2 id="email-heading" class="cdh-card__title">Correo electrónico</h2>
                    </div>

                    <div class="cdh-card__body">
                        <AppAlert
                            v-if="emailFeedback"
                            :variant="emailFeedback.variant"
                            class="mb-3"
                        >
                            {{ emailFeedback.text }}
                        </AppAlert>

                        <form novalidate @submit.prevent="submitEmail">
                            <div class="cdh-stack-4">
                                <AppFormField
                                    v-model="email.value"
                                    name="email"
                                    label="Nuevo correo electrónico"
                                    type="email"
                                    inputmode="email"
                                    autocomplete="email"
                                    required
                                    :disabled="savingEmail"
                                    :error="email.errors.email ?? null"
                                />

                                <AppPasswordField
                                    v-model="email.currentPassword"
                                    v-model:visible="email.currentPasswordVisible"
                                    name="current_password"
                                    label="Contraseña actual"
                                    autocomplete="current-password"
                                    required
                                    :disabled="savingEmail"
                                    hint="Por seguridad, confirmar su contraseña actual."
                                    :error="email.errors.current_password ?? null"
                                />

                                <AppButton type="submit" :busy="savingEmail">
                                    Actualizar correo
                                </AppButton>
                            </div>
                        </form>
                    </div>
                </section>

                <!-- Password: requires the current password. -->
                <section class="cdh-card" aria-labelledby="password-heading">
                    <div class="cdh-card__header">
                        <h2 id="password-heading" class="cdh-card__title">Contraseña</h2>
                    </div>

                    <div class="cdh-card__body">
                        <AppAlert
                            v-if="passwordFeedback"
                            :variant="passwordFeedback.variant"
                            class="mb-3"
                        >
                            {{ passwordFeedback.text }}
                        </AppAlert>

                        <form novalidate @submit.prevent="submitPassword">
                            <div class="cdh-stack-4">
                                <AppPasswordField
                                    v-model="password.currentPassword"
                                    v-model:visible="password.currentVisible"
                                    name="current_password"
                                    label="Contraseña actual"
                                    autocomplete="current-password"
                                    required
                                    :disabled="savingPassword"
                                    :error="password.errors.current_password ?? null"
                                />

                                <AppPasswordField
                                    v-model="password.value"
                                    v-model:visible="password.visible"
                                    name="password"
                                    label="Nueva contraseña"
                                    autocomplete="new-password"
                                    required
                                    :disabled="savingPassword"
                                    hint="Mínimo 12 caracteres, con mayúsculas, minúsculas y números."
                                    :error="password.errors.password ?? null"
                                />

                                <AppPasswordField
                                    v-model="password.confirmation"
                                    v-model:visible="password.confirmationVisible"
                                    name="password_confirmation"
                                    label="Confirme la nueva contraseña"
                                    autocomplete="new-password"
                                    required
                                    :disabled="savingPassword"
                                    :error="password.errors.password_confirmation ?? null"
                                />

                                <AppButton type="submit" :busy="savingPassword">
                                    Actualizar contraseña
                                </AppButton>

                                <p class="cdh-text-xs cdh-text-muted mb-0">
                                    Al cambiar su contraseña se cerrarán automáticamente las
                                    demás sesiones abiertas con la cuenta.
                                </p>
                            </div>
                        </form>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
