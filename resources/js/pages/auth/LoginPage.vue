<script setup lang="ts">
import { computed, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import AppWordmark from '@/components/brand/AppWordmark.vue';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppFormField from '@/components/ui/AppFormField.vue';
import AppPasswordField from '@/components/ui/AppPasswordField.vue';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';

/**
 * Sign in.
 *
 * The page never reveals whether an address exists: the server answers a wrong
 * password and an inactive account identically, and that single message is what
 * is shown here.
 */
const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const email = ref('');
const password = ref('');
const passwordVisible = ref(false);
const remember = ref(false);
const formError = ref<string | null>(null);
const fieldErrors = ref<Record<string, string | null>>({});

const redirect = computed(() => {
    const target = route.query.redirect;

    // Only accept a same application path, never an absolute URL.
    return typeof target === 'string' && target.startsWith('/') && !target.startsWith('//')
        ? target
        : '/';
});

function fieldError(field: string): string | null {
    return fieldErrors.value[field] ?? null;
}

async function submit(): Promise<void> {
    formError.value = null;
    fieldErrors.value = {};

    try {
        await auth.login(email.value, password.value, remember.value);

        await router.replace(redirect.value);
    } catch (error) {
        if (error instanceof ApiError) {
            fieldErrors.value = {
                email: error.fieldError('email'),
                password: error.fieldError('password'),
            };

            // A rate limit is reported as is, because waiting is the real advice.
            formError.value =
                error.retryAfter !== null
                    ? `Demasiados intentos. Espere ${error.retryAfter} segundos antes de volver a intentarlo.`
                    : error.message;
        } else {
            formError.value = 'No fue posible iniciar sesión. Inténtelo de nuevo.';
        }
    }
}
</script>

<template>
    <div class="cdh-auth">
        <!-- Marketing/context panel: hidden on small screens. -->
        <aside class="cdh-auth__aside">
            <AppWordmark light />

            <div>
                <p class="cdh-auth__aside-title">
                    Administración clara para la gestión de clientes
                </p>

                <p class="cdh-auth__aside-text">
                    Consultora DH centraliza la información de clientes, empresas,
                    Affiliaciones y pagos en un solo lugar, con la trazabilidad que
                    exige el trabajo diario.
                </p>

                <ul class="cdh-auth__aside-list">
                    <li>
                        <i class="bi bi-shield-check" aria-hidden="true" />
                        <span>Acceso protegido y registro de auditoría</span>
                    </li>
                    <li>
                        <i class="bi bi-people" aria-hidden="true" />
                        <span>Gestión de clientes, empresas y afiliaciones</span>
                    </li>
                    <li>
                        <i class="bi bi-calendar-check" aria-hidden="true" />
                        <span>Periodos, cortes y cartera</span>
                    </li>
                    <li>
                        <i class="bi bi-file-earmark-text" aria-hidden="true" />
                        <span>Documentos, reportes y planillas</span>
                    </li>
                </ul>
            </div>

            <p class="cdh-auth__aside-footer">
                Plataforma de uso interno · Acceso autorizado únicamente
            </p>
        </aside>

        <!-- Sign in form. -->
        <main class="cdh-auth__panel">
            <div class="cdh-auth__form">
                <AppWordmark class="d-md-none mb-4" />

                <h1 class="cdh-auth__title">Iniciar sesión</h1>
                <p class="cdh-auth__subtitle">
                    Ingrese con sus credenciales institucionales para continuar.
                </p>

                <AppAlert v-if="formError" variant="danger" class="mb-3">
                    {{ formError }}
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
                            :disabled="auth.signingIn"
                            :error="fieldError('email')"
                        />

                        <div>
                            <AppPasswordField
                                v-model="password"
                                v-model:visible="passwordVisible"
                                name="password"
                                label="Contraseña"
                                autocomplete="current-password"
                                placeholder="Su contraseña"
                                required
                                :disabled="auth.signingIn"
                                :error="fieldError('password')"
                            />

                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <div class="form-check">
                                    <input
                                        id="remember"
                                        v-model="remember"
                                        class="form-check-input"
                                        type="checkbox"
                                        :disabled="auth.signingIn"
                                    />
                                    <label class="form-check-label cdh-text-sm" for="remember">
                                        Recordar esta sesión
                                    </label>
                                </div>

                                <RouterLink to="/forgot-password" class="cdh-text-sm">
                                    ¿Olvidó su contraseña?
                                </RouterLink>
                            </div>
                        </div>

                        <AppButton
                            type="submit"
                            block
                            :busy="auth.signingIn"
                        >
                            Ingresar
                        </AppButton>
                    </div>
                </form>

                <p class="cdh-auth__footer">
                    El acceso está restringido a personal autorizado.
                </p>
            </div>
        </main>
    </div>
</template>
