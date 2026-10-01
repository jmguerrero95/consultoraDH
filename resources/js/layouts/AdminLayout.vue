<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import AdminSidebar from '@/components/layout/AdminSidebar.vue';
import AdminTopbar from '@/components/layout/AdminTopbar.vue';
import AppToasts from '@/components/ui/AppToasts.vue';

/**
 * Application shell for every authenticated screen.
 *
 * On desktop the sidebar is permanent; below 992px it becomes an off-canvas
 * panel that closes on navigation, on Escape and when the backdrop is clicked.
 */
const route = useRoute();

const sidebarOpen = ref(false);

function closeSidebar(): void {
    sidebarOpen.value = false;
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        closeSidebar();
    }
}

watch(
    () => route.fullPath,
    () => closeSidebar(),
);

watch(sidebarOpen, (open) => {
    document.body.classList.toggle('cdh-no-scroll', open);
});

document.addEventListener('keydown', onKeydown);

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    document.body.classList.remove('cdh-no-scroll');
});
</script>

<template>
    <div class="cdh-shell">
        <a class="cdh-skip-link" href="#cdh-main-content">Saltar al contenido principal</a>

        <aside
            id="cdh-sidebar"
            class="cdh-sidebar"
            :class="{ 'cdh-sidebar--open': sidebarOpen }"
            :aria-hidden="undefined"
        >
            <AdminSidebar />
        </aside>

        <button
            v-if="sidebarOpen"
            type="button"
            class="cdh-sidebar__scrim"
            aria-label="Cerrar menú de navegación"
            @click="closeSidebar"
        />

        <div class="cdh-main">
            <AdminTopbar @toggle-sidebar="sidebarOpen = !sidebarOpen" />

            <main id="cdh-main-content" class="cdh-content" tabindex="-1">
                <RouterView />
            </main>
        </div>

        <AppToasts />
    </div>
</template>

<style>
/* Prevent the page behind the off-canvas sidebar from scrolling. */
.cdh-no-scroll {
    overflow: hidden;
}
</style>
