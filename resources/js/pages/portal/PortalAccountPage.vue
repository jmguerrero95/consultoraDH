<script setup lang="ts">
import { onMounted, ref } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { fecha, pesos } from '@/composables/useFormatters';
import { a05 } from '@/services/a05';
import { ApiError } from '@/services/http';

import type { ClientAccountPayload } from '@/types/api';

/**
 * La cuenta del cliente en el portal.
 *
 * §46: estas cifras **son** las de A03. No se recalculan aquí; el endpoint del portal
 * llama al mismo servicio que la pantalla interna de cartera, y por eso las dos no
 * pueden discrepar. Tampoco se guarda ninguna columna de saldo en el cliente (§9.4).
 */

const cuenta = ref<ClientAccountPayload | null>(null);
const cargando = ref(true);
const errorMessage = ref<string | null>(null);

onMounted(async () => {
    try {
        cuenta.value = await a05.portal.financialAccount();
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo cargar su cuenta.';
    } finally {
        cargando.value = false;
    }
});
</script>

<template>
    <div>
        <h1 class="h4 mb-3">Mi cuenta</h1>

        <AppAlert v-if="errorMessage" variant="danger" :message="errorMessage" class="mb-3" />
        <AppLoading v-if="cargando" />

        <template v-else-if="cuenta">
            <div class="row g-3 mb-3">
                <div class="col-12 col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h2 class="h6 text-body-secondary">Saldo pendiente</h2>
                            <p class="fs-5 mb-0">{{ pesos(cuenta.summary.outstanding_balance_cop) }}</p>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h2 class="h6 text-body-secondary">Saldo vencido</h2>
                            <p class="fs-5 mb-0">{{ pesos(cuenta.summary.overdue_balance_cop) }}</p>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h2 class="h6 text-body-secondary">Crédito no aplicado</h2>
                            <p class="fs-5 mb-0">{{ pesos(cuenta.summary.unallocated_credit_cop) }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="h6 mb-0">Detalle por periodo</h2>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <caption class="visually-hidden">Obligaciones por periodo</caption>
                        <thead>
                            <tr>
                                <th scope="col">Periodo</th>
                                <th scope="col">Empresa</th>
                                <th scope="col" class="text-end">Valor</th>
                                <th scope="col" class="text-end">Pagado</th>
                                <th scope="col" class="text-end">Saldo</th>
                                <th scope="col">Vence</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="fila in cuenta.obligations ?? []" :key="fila.id">
                                <td>{{ fila.period_label ?? fila.period_key ?? '—' }}</td>
                                <td>{{ fila.company_name ?? '—' }}</td>
                                <td class="text-end">{{ pesos(fila.effective_amount_cop) }}</td>
                                <td class="text-end">{{ pesos(fila.paid_amount_cop) }}</td>
                                <td class="text-end">{{ pesos(fila.balance_cop) }}</td>
                                <td>{{ fecha(fila.due_on) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>