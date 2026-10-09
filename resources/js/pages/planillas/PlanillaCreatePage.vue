<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppLoading from '@/components/ui/AppLoading.vue';
import { a05 } from '@/services/a05';
import { businessApi } from '@/services/api';
import { ApiError } from '@/services/http';

import type { PlanillaOperatorOption, VocabularyOption } from '@/types/api';

/**
 * Generar una planilla.
 *
 * §20 en la interfaz: se muestra una vista previa y **se crea exactamente lo que se
 * mostró**. La vista previa devuelve un `source_digest`; ese identificador viaja a la
 * creación, y si la topología cambió entre medias el servidor responde 409 y esta
 * pantalla obliga a previsualizar de nuevo en lugar de crear en silencio otra lista.
 *
 * Por eso el botón de crear permanece deshabilitado hasta que hay una vista previa,
 * y el texto de error del 409 dice explícitamente que hay que repetirla.
 */

const router = useRouter();

const periods = ref<{ id: number; period_month: string }[]>([]);
const companies = ref<{ id: number; legal_name: string }[]>([]);
const operators = ref<PlanillaOperatorOption[]>([]);
const statuses = ref<VocabularyOption[]>([]);

const periodId = ref('');
const companyId = ref('');
const operator = ref('');
const operatorOtherName = ref('');
const notes = ref('');

const cargando = ref(true);
const previsualizando = ref(false);
const creando = ref(false);
const errorMessage = ref<string | null>(null);
const avisoPrevio = ref<string | null>(null);

const digest = ref<string | null>(null);
const candidatos = ref<{ client_id: number; document_number: string; client_name: string }[]>([]);

const requiereNombre = computed(
    () => operators.value.find((o) => o.value === operator.value)?.requires_name === true,
);

const listoParaCrear = computed(
    () =>
        digest.value !== null &&
        periodId.value !== '' &&
        companyId.value !== '' &&
        operator.value !== '' &&
        (!requiereNombre.value || operatorOtherName.value.trim() !== ''),
);

onMounted(async () => {
    try {
        const [vocabulario, listaPeriodos, listaEmpresas] = await Promise.all([
            a05.planillas.vocabulary(),
            businessApi.periods.list().catch(() => null),
            businessApi.companies.list().catch(() => null),
        ]);

        operators.value = vocabulario.operators as PlanillaOperatorOption[];
        statuses.value = vocabulario.statuses;
        periods.value = (listaPeriodos?.items ?? []) as { id: number; period_month: string }[];
        companies.value = (listaEmpresas?.companies ?? []) as { id: number; legal_name: string }[];
    } catch {
        errorMessage.value = 'No se pudo cargar la información necesaria.';
    } finally {
        cargando.value = false;
    }
});

async function previsualizar(): Promise<void> {
    avisoPrevio.value = null;
    errorMessage.value = null;
    digest.value = null;
    candidatos.value = [];

    if (periodId.value === '' || companyId.value === '') {
        errorMessage.value = 'Seleccione el periodo y la empresa.';

        return;
    }

    previsualizando.value = true;

    try {
        const previa = await a05.planillas.preview({
            period_id: Number(periodId.value),
            company_id: Number(companyId.value),
        });

        digest.value = previa.source_digest;
        candidatos.value = previa.candidates;

        avisoPrevio.value =
            previa.candidate_count === 0
                ? 'No hay personas que se intersecten con este periodo. La planilla quedaría vacía y no podrá validarse.'
                : `Se generará una planilla con ${previa.candidate_count} persona(s).`;
    } catch (e) {
        errorMessage.value = e instanceof ApiError ? e.message : 'No se pudo generar la vista previa.';
    } finally {
        previsualizando.value = false;
    }
}

async function crear(): Promise<void> {
    if (digest.value === null) {
        return;
    }

    errorMessage.value = null;
    creando.value = true;

    try {
        const planilla = await a05.planillas.create({
            period_id: Number(periodId.value),
            company_id: Number(companyId.value),
            operator: operator.value,
            operator_other_name: requiereNombre.value ? operatorOtherName.value : null,
            notes: notes.value || null,
            source_digest: digest.value,
        });

        await router.push(`/planillas/${planilla.id}`);
    } catch (e) {
        // El 409 es el caso previsto: la vista previa quedó obsoleta.
        if (e instanceof ApiError && e.status === 409) {
            digest.value = null;
            candidatos.value = [];
            errorMessage.value =
                'La información cambió desde la vista previa. Vuelva a previsualizar antes de crear.';
        } else {
            errorMessage.value =
                e instanceof ApiError ? e.message : 'No se pudo crear la planilla.';
        }
    } finally {
        creando.value = false;
    }
}
</script>

<template>
    <div class="container-fluid py-4">
        <h1 class="h4 mb-3">Generar planilla</h1>

        <AppLoading v-if="cargando" />

        <AppAlert v-if="errorMessage" variant="danger" :message="errorMessage" class="mb-3" />
        <AppAlert v-if="avisoPrevio" variant="warning" :message="avisoPrevio" class="mb-3" />

        <div class="row g-3">
            <div class="col-12 col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h2 class="h6 mb-0">Datos de la planilla</h2>
                    </div>
                    <div class="card-body d-grid gap-3">
                        <div>
                            <label class="form-label" for="periodo">Periodo</label>
                            <select id="periodo" v-model="periodId" class="form-select">
                                <option value="">Seleccione…</option>
                                <option v-for="p in periods" :key="p.id" :value="p.id">
                                    {{ p.period_month }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label" for="empresa">Empresa</label>
                            <select id="empresa" v-model="companyId" class="form-select">
                                <option value="">Seleccione…</option>
                                <option v-for="c in companies" :key="c.id" :value="c.id">
                                    {{ c.legal_name }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label" for="operador">Operador</label>
                            <select id="operador" v-model="operator" class="form-select">
                                <option value="">Seleccione…</option>
                                <option v-for="o in operators" :key="o.value" :value="o.value">
                                    {{ o.label }}
                                </option>
                            </select>
                        </div>

                        <div v-if="requiereNombre">
                            <label class="form-label" for="nombre-operador">
                                Nombre del operador
                            </label>
                            <input
                                id="nombre-operador"
                                v-model="operatorOtherName"
                                class="form-control"
                            />
                            <div class="form-text">
                                Obligatorio cuando la opción es «Otro».
                            </div>
                        </div>

                        <div>
                            <label class="form-label" for="notas">Notas internas</label>
                            <textarea id="notas" v-model="notes" class="form-control" rows="3" />
                        </div>

                        <AppButton
                            variant="secondary"
                            :loading="previsualizando"
                            :disabled="periodId === '' || companyId === ''"
                            @click="previsualizar"
                        >
                            Ver vista previa
                        </AppButton>

                        <AppButton
                            variant="primary"
                            :loading="creando"
                            :disabled="!listoParaCrear"
                            @click="crear"
                        >
                            Crear planilla
                        </AppButton>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-7">
                <div class="card">
                    <div class="card-header">
                        <h2 class="h6 mb-0">
                            Vista previa
                            <span
                                v-if="digest"
                                class="badge text-bg-light ms-2"
                                title="Identidad de la evidencia usada en esta vista previa"
                            >
                                evidencia fijada
                            </span>
                        </h2>
                    </div>
                    <div class="card-body">
                        <p
                            v-if="digest === null"
                            class="text-body-secondary mb-0"
                        >
                            Seleccione periodo y empresa, y pulse «Ver vista previa». Nadie se
                            incluye en una planilla sin que usted lo haya visto antes.
                        </p>

                        <div v-else class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <caption class="visually-hidden">Personas de la vista previa</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Documento</th>
                                        <th scope="col">Nombre</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="c in candidatos" :key="c.client_id">
                                        <td>{{ c.document_number }}</td>
                                        <td>{{ c.client_name }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>