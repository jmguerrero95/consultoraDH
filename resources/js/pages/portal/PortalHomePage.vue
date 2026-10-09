<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { pesos } from '@/composables/useFormatters';
import { a05 } from '@/services/a05';
import { ApiError } from '@/services/http';

import type { PortalHome as PortalHomePayload } from '@/types/api';

/**
 * La portada del portal.
 *
 * §42: sólo información de **este** cliente. El resumen financiero viene del servicio
 * de A03 a través de un endpoint con propiedad —no recalculado aquí— y no se muestran
 * novedades internas, notas del personal ni tareas de otros: no pertenecen al portal.
 */

const home = ref<PortalHomePayload | null>(null);
const cuenta = ref<{ summary?: { outstanding_balance_cop?: number; overdue_balance_cop?: number } } | null>(
    null,
);
const cargando = ref(true);
const errorMessage = ref<string | null>(null);

onMounted(async () => {
    try {
        const [resumen, financiero] = await Promise.all([
            a05.portal.home(),
            a05.portal.financialAccount().catch(() => null),
        ]);

        home.value = resumen;
        cuenta.value = financiero;
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo cargar el portal.';
    } finally {
        cargando.value = false;
    }
});
</script>

<template>
    <div>
        <h1 class="h4 mb-3">Hola</h1>

        <AppAlert v-if="errorMessage" variant="danger" :message="errorMessage" class="mb-3" />
        <AppLoading v-if="cargando" />

        <template v-else-if="home">
            <div class="row g-3">
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h2 class="h6 text-body-secondary">Sus datos</h2>
                            <p class="fs-5 mb-0">{{ home.client_name }}</p>
                            <RouterLink to="/portal/perfil" class="small">Ver o actualizar mi perfil</RouterLink>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h2 class="h6 text-body-secondary">Estado de cuenta</h2>
                            <p class="mb-1">
                                Saldo pendiente:
                                <strong>{{ pesos(cuenta?.summary?.outstanding_balance_cop ?? 0) }}</strong>
                            </p>
                            <p class="mb-0">
                                Saldo vencido:
                                <strong>{{ pesos(cuenta?.summary?.overdue_balance_cop ?? 0) }}</strong>
                            </p>
                            <RouterLink to="/portal/cuenta" class="small">Ver detalle</RouterLink>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h2 class="h6 text-body-secondary">Pendientes</h2>
                            <p class="mb-1">
                                Solicitudes de documentos: <strong>{{ home.pending_document_requests }}</strong>
                            </p>
                            <p class="mb-1">
                                Documentos disponibles: <strong>{{ home.visible_documents }}</strong>
                            </p>
                            <p class="mb-0">
                                Solicitudes de actualización de perfil:
                                <strong>{{ home.open_update_requests }}</strong>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>