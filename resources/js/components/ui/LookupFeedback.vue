<script setup lang="ts">
/**
 * What a search-backed `<select>` is doing, underneath itself.
 *
 * A list that is still loading, a list that failed and a list that searched and found nothing
 * all look the same from the field: one disabled option reading "Seleccione una empresa". An
 * operator cannot tell "still loading" from "there are no companies", so the field said
 * nothing at all and the only way to find out was to type something else and watch.
 *
 * §48 A needed a three-level cutoff hierarchy configured through this page, and a select that
 * cannot say why it is empty is a select nobody can rely on. So the three states are named,
 * and the empty one is reported rather than implied by the absence of options.
 */
withDefaults(
    defineProps<{
        /** The list is on its way. */
        loading?: boolean;
        /** The list could not be fetched. */
        failed?: boolean;
        /** The noun used in the message, in the plural: `clientes`, `empresas`. */
        empty: string;
    }>(),
    {
        loading: false,
        failed: false,
    },
);
</script>

<template>
    <p v-if="loading" class="cdh-form-hint" role="status">Buscando…</p>
    <p v-else-if="failed" class="cdh-form-hint" role="status">
        No fue posible cargar la lista de {{ empty }}. Intente de nuevo.
    </p>
    <p v-else class="cdh-form-hint" role="status">
        Sin resultados. Escriba para buscar {{ empty }}.
    </p>
</template>