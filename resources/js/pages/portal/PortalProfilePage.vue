<script setup lang="ts">
import { onMounted, ref } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { fecha } from '@/composables/useFormatters';
import { a05 } from '@/services/a05';
import { ApiError } from '@/services/http';

import type { ClientProfileUpdateRequest, PortalProfile } from '@/types/api';

/**
 * Mi perfil.
 *
 * §43/§44: el cliente **propone** cambios, no los aplica. Enviar este formulario crea
 * una solicitud que unmember del personal revisa; el registro del cliente no se toca
 * hasta que esa revisión la apruebe usando la acción de actualización de A02.
 *
 * Los campos de identidad —tipo y número de documento— no son editables aquí a
 * propósito: corregirlos es una decisión que el personal toma, no un ajuste que un
 * titular de cuenta escriba por su cuenta.
 */

const perfil = ref<PortalProfile | null>(null);
const solicitudes = ref<ClientProfileUpdateRequest[]>([]);

const formulario = ref({
    first_names: '',
    last_names: '',
    email: '',
    phone: '',
    address: '',
});

const cargando = ref(true);
const errorMessage = ref<string | null>(null);
const actionMessage = ref<string | null>(null);
const enviando = ref(false);

onMounted(async () => {
    try {
        perfil.value = await a05.portal.profile();
        formulario.value = {
            first_names: perfil.value.first_names,
            last_names: perfil.value.last_names,
            email: perfil.value.email ?? '',
            phone: perfil.value.phone ?? '',
            address: perfil.value.address ?? '',
        };

        const lista = await a05.portal.updateRequests();
        solicitudes.value = lista.data;
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo cargar su perfil.';
    } finally {
        cargando.value = false;
    }
});

async function enviar(): Promise<void> {
    errorMessage.value = null;
    actionMessage.value = null;
    enviando.value = true;

    try {
        await a05.portal.submitUpdateRequest(formulario.value);

        actionMessage.value =
            'Su solicitud fue registrada. Nuestro equipo la revisará antes de aplicarla.';

        const lista = await a05.portal.updateRequests();
        solicitudes.value = lista.data;
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo enviar la solicitud.';
    } finally {
        enviando.value = false;
    }
}
</script>

<template>
    <div>
        <h1 class="h4 mb-3">Mi perfil</h1>

        <AppAlert v-if="errorMessage" variant="danger" :message="errorMessage" class="mb-3" />
        <AppAlert v-if="actionMessage" variant="success" :message="actionMessage" class="mb-3" />
        <AppLoading v-if="cargando" />

        <div v-else-if="perfil" class="row g-3">
            <div class="col-12 col-lg-7">
                <div class="card">
                    <div class="card-header">
                        <h2 class="h6 mb-0">Proponer cambios</h2>
                    </div>
                    <div class="card-body">
                        <p class="small text-body-secondary">
                            Sus datos de identidad (tipo y número de documento) no se editan
                            aquí: envíe su solicitud y el personal la revisa.
                        </p>

                        <div class="row g-2">
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="nombres">Nombres</label>
                                <input
                                    id="nombres"
                                    v-model="formulario.first_names"
                                    class="form-control"
                                />
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="apellidos">Apellidos</label>
                                <input
                                    id="apellidos"
                                    v-model="formulario.last_names"
                                    class="form-control"
                                />
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="correo">Correo</label>
                                <input id="correo" v-model="formulario.email" type="email" class="form-control" />
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="telefono">Teléfono</label>
                                <input id="telefono" v-model="formulario.phone" class="form-control" />
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="direccion">Dirección</label>
                                <input
                                    id="direccion"
                                    v-model="formulario.address"
                                    class="form-control"
                                />
                            </div>
                        </div>

                        <AppButton variant="primary" class="mt-3" :loading="enviando" @click="enviar">
                            Enviar solicitud de actualización
                        </AppButton>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h2 class="h6 mb-0">Mis solicitudes</h2>
                    </div>
                    <div v-if="solicitudes.length === 0" class="card-body">
                        <AppEmptyState
                            title="Sin solicitudes"
                            description="No ha enviado solicitudes de actualización."
                        />
                    </div>
                    <div v-else class="list-group list-group-flush">
                        <div
                            v-for="s in solicitudes"
                            :key="s.id"
                            class="list-group-item py-3"
                        >
                            <div class="d-flex justify-content-between">
                                <span
                                    class="badge"
                                    :class="{
                                        'text-bg-primary': s.status === 'pending',
                                        'text-bg-success': s.status === 'approved',
                                        'text-bg-danger': s.status === 'rejected',
                                        'text-bg-secondary': s.status === 'cancelled',
                                    }"
                                >
                                    {{ s.status_label }}
                                </span>
                                <small class="text-body-secondary">{{ fecha(s.created_at) }}</small>
                            </div>
                            <ul class="small mb-0 mt-2">
                                <li v-for="(valor, campo) in s.proposed_changes" :key="campo">
                                    {{ campo }}: {{ valor }}
                                </li>
                            </ul>
                            <p v-if="s.review_note" class="small text-danger mb-0 mt-1">
                                {{ s.review_note }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>