<script setup lang="ts">
import { onMounted, ref } from 'vue';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { fecha } from '@/composables/useFormatters';
import { a05 } from '@/services/a05';
import { ApiError } from '@/services/http';

import type { PortalAffiliation, PortalRelationship } from '@/types/api';

/**
 * Mis relaciones y afiliaciones.
 *
 * §45: reutiliza la representación de A02, pero por una consulta con propiedad. No se
 * llama a un endpoint interno ni se concede un permiso interno para verlo: la propiedad
 * sustituye al permiso, y por eso no aparecen notas internas ni otros clientes.
 */

const relaciones = ref<PortalRelationship[]>([]);
const afiliaciones = ref<PortalAffiliation[]>([]);
const cargando = ref(true);
const errorMessage = ref<string | null>(null);

onMounted(async () => {
    try {
        const payload = await a05.portal.relationships();

        relaciones.value = payload.relationships;
        afiliaciones.value = payload.affiliations;
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudieron cargar sus relaciones.';
    } finally {
        cargando.value = false;
    }
});
</script>

<template>
    <div>
        <h1 class="h4 mb-3">Mis relaciones</h1>

        <AppAlert v-if="errorMessage" variant="danger" :message="errorMessage" class="mb-3" />
        <AppLoading v-if="cargando" />

        <div v-else class="card mb-3">
            <div class="card-header">
                <h2 class="h6 mb-0">Empresas</h2>
            </div>
            <div v-if="relaciones.length === 0" class="card-body">
                <AppEmptyState title="Sin relaciones" description="No hay empresas registradas." />
            </div>
            <div v-else class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <caption class="visually-hidden">Relaciones con empresas</caption>
                    <thead>
                        <tr>
                            <th scope="col">Empresa</th>
                            <th scope="col">NIT</th>
                            <th scope="col">Cargo</th>
                            <th scope="col">Desde</th>
                            <th scope="col">Hasta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in relaciones" :key="r.id">
                            <td>{{ r.company_name }}</td>
                            <td>{{ r.company_tax_id }}</td>
                            <td>{{ r.job_title ?? '—' }}</td>
                            <td>{{ fecha(r.started_on) }}</td>
                            <td>{{ r.ended_on ? fecha(r.ended_on) : 'Vigente' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="h6 mb-0">Afiliaciones</h2>
            </div>
            <div v-if="afiliaciones.length === 0" class="card-body">
                <AppEmptyState
                    title="Sin afiliaciones"
                    description="No hay afiliaciones registradas."
                />
            </div>
            <div v-else class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <caption class="visually-hidden">Afiliaciones</caption>
                    <thead>
                        <tr>
                            <th scope="col">Tipo</th>
                            <th scope="col">Entidad</th>
                            <th scope="col">Desde</th>
                            <th scope="col">Hasta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in afiliaciones" :key="a.id">
                            <td>{{ a.type }}</td>
                            <td>{{ a.entity_name ?? '—' }}</td>
                            <td>{{ fecha(a.started_on) }}</td>
                            <td>{{ a.ended_on ? fecha(a.ended_on) : 'Vigente' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>