<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink } from 'vue-router';

import AppWordmark from '@/components/brand/AppWordmark.vue';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppFormField from '@/components/ui/AppFormField.vue';
import { api } from '@/services/api';
import { ApiError } from '@/services/http';

/**
 * Password recovery, step one.
 *
 * The answer is the same whether or not the address is registered, so this page
 * cannot be used to discover accounts.
 */
const email = ref('');
const busy = ref(false);
const sent = ref(false);
const message = ref<string | null>(null);
const error = ref<string | null>(null);
const fieldErrors = ref<Record<string, string | null>>({});

async function submit(): Promise<void> {
    busy.value = true;
    error.value = null;
    fieldErrors.value = {};

    try {
        const response = await api.auth.forgotPassword(email.value);

        message.value = response.message;
        sent.value = true;
    } catch (caught) {
        if (caught instanceof ApiError) {
            fieldErrors.value = { email: caught.fieldError('email') };
            error.value = caught.message;
        } else {
            error.value = 'No fue posible solicitar el restablecimiento.';
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

                <template v-if="!sent">
                    <h1 class="cdh-auth__title">Recuperar contraseña</h1>
                    <p class="cdh-auth__subtitle">
                        Ingrese su correo electrónico y le enviaremos un enlace para
                        restablecer la contraseña.
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
                                placeholder="nombre@consultora-dh.local"
                                required
                                :disabled="busy"
                                :error="fieldErrors.email ?? null"
                            />

                            <AppButton type="submit" block :busy="busy">
                                Enviar enlace
                            </AppButton>
                        </div>
                    </form>
                </template>

                <template v-else>
                    <h1 class="cdh-auth__title">Revise su correo</h1>

                    <AppAlert variant="success" class="mb-3">
                        {{ message }}
                    </AppAlert>

                    <p class="cdh-text-sm cdh-text-muted">
                        En el entorno local el mensaje queda registrado en el archivo
                        <code class="cdh-mono">storage/logs/laravel.log</code> en lugar de
                        enviarse por correo, para que el flujo pueda probarse sin configurar
                        un servidor SMTP.
                    </p>

                    <AppButton variant="secondary" block class="mt-3" @click="sent = false">
                        Solicitar otro enlace
                    </AppButton>
                </template>
            </div>
        </main>
    </div>
</template>
