<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';

import AppWordmark from '@/components/brand/AppWordmark.vue';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppFormField from '@/components/ui/AppFormField.vue';
import AppPasswordField from '@/components/ui/AppPasswordField.vue';
import { api } from '@/services/api';
import { ApiError } from '@/services/http';

/**
 * Password recovery, step two: choose a new password.
 *
 * The link that arrives by email points here with the token in the URL. The
 * address is carried along in the query string so the user does not have to type
 * it again.
 */
const props = defineProps<{ token: string }>();

const router = useRouter();

const email = ref('');
const password = ref('');
const passwordVisible = ref(false);
const confirmation = ref('');
const confirmationVisible = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);
const fieldErrors = ref<Record<string, string | null>>({});

function fieldError(field: string): string | null {
    return fieldErrors.value[field] ?? null;
}

async function submit(): Promise<void> {
    busy.value = true;
    error.value = null;
    fieldErrors.value = {};

    try {
        const response = await api.auth.resetPassword({
            token: props.token,
            email: email.value,
            password: password.value,
            password_confirmation: confirmation.value,
        });

        await router.replace({
            name: 'login',
            query: { reset: '1' },
            state: { message: response.message },
        });
    } catch (caught) {
        if (caught instanceof ApiError) {
            fieldErrors.value = {
                email: caught.fieldError('email'),
                password: caught.fieldError('password'),
            };
            error.value = caught.message;
        } else {
            error.value = 'No fue posible restablecer la contraseña.';
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="cdh-auth">
        <main class="cdh-auth__panel">
            <div class="cdh-auth__form">
                <AppWordmark class="mb-4" />

                <RouterLink to="/login" class="cdh-btn cdh-btn--ghost btn btn-sm cdh-auth__back">
                    <i class="bi bi-arrow-left" aria-hidden="true" />
                    <span>Volver al inicio de sesión</span>
                </RouterLink>

                <h1 class="cdh-auth__title">Restablecer contraseña</h1>
                <p class="cdh-auth__subtitle">
                    Elija una nueva contraseña para su cuenta.
                </p>

                <AppAlert v-if="error" variant="danger" class="mb-3">
                    {{ error }}
                </AppAlert>

                <form novalidate @submit.prevent="submit">
                    <div class="cdh-stack-4">
                        <AppFormField
                            v-model="email"
                            name="email"
                            label="Correo electrónico"
                            type="email"
                            inputmode="email"
                            autocomplete="username"
                            required
                            :disabled="busy"
                            :error="fieldError('email')"
                        />

                        <AppPasswordField
                            v-model="password"
                            v-model:visible="passwordVisible"
                            name="password"
                            label="Nueva contraseña"
                            autocomplete="new-password"
                            required
                            :disabled="busy"
                            hint="Mínimo 12 caracteres, con mayúsculas, minúsculas y números."
                            :error="fieldError('password')"
                        />

                        <AppPasswordField
                            v-model="confirmation"
                            v-model:visible="confirmationVisible"
                            name="password_confirmation"
                            label="Confirme la nueva contraseña"
                            autocomplete="new-password"
                            required
                            :disabled="busy"
                            :error="fieldError('password_confirmation')"
                        />

                        <AppButton type="submit" block :busy="busy">
                            Guardar nueva contraseña
                        </AppButton>
                    </div>
                </form>
            </div>
        </main>
    </div>
</template>
