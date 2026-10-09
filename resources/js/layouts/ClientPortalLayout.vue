<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink, RouterView, useRouter } from 'vue-router';

import { useAuthStore } from '@/stores/auth';
import { api } from '@/services/api';

/**
 * El portal del cliente.
 *
 * §41: un área autenticada **distinta** dentro de la misma aplicación, con su propio
 * layout. No renderiza la barra lateral administrativa y no la debe renderizar nunca:
 * un cliente no ve, ni por un fallo de maquetación, el menú de la operación interna.
 *
 * Que la ruta sea `/portal` es presentación. La autoridad es el servidor, que comprueba
 * `account_type` y la pertenencia del recurso al cliente en cada petición.
 */

const auth = useAuthStore();
const router = useRouter();

const navigation = computed(() => [
    { to: '/portal', label: 'Inicio', icon: 'bi-house-door', exact: true },
    { to: '/portal/cuenta', label: 'Mi cuenta', icon: 'bi-wallet2', exact: false },
    { to: '/portal/relaciones', label: 'Mis relaciones', icon: 'bi-diagram-3', exact: false },
    { to: '/portal/documentos', label: 'Documentos', icon: 'bi-folder2-open', exact: false },
    { to: '/portal/solicitudes', label: 'Solicitudes', icon: 'bi-inbox', exact: false },
    { to: '/portal/perfil', label: 'Mi perfil', icon: 'bi-person-circle', exact: false },
]);

async function cerrarSesion(): Promise<void> {
    await api.auth.logout().catch(() => undefined);
    auth.clear();
    await router.push({ name: 'login' });
}
</script>

<template>
    <div class="d-flex flex-column min-vh-100 bg-body-tertiary">
        <header class="bg-white border-bottom">
            <div class="container py-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
                <RouterLink to="/portal" class="d-flex align-items-center gap-2 text-decoration-none">
                    <span class="fw-semibold text-primary">Consultora DH</span>
                    <span class="badge text-bg-light">Portal de clientes</span>
                </RouterLink>

                <div class="d-flex align-items-center gap-3">
                    <span class="small text-body-secondary d-none d-sm-inline">
                        {{ auth.user?.name }}
                    </span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="cerrarSesion">
                        Salir
                    </button>
                </div>
            </div>

            <nav class="container" aria-label="Navegación del portal">
                <ul class="nav nav-underline flex-nowrap overflow-auto">
                    <li v-for="item in navigation" :key="item.to" class="nav-item">
                        <RouterLink
                            :to="item.to"
                            class="nav-link py-2"
                            :class="{ active: item.exact ? $route.path === item.to : $route.path.startsWith(item.to) }"
                        >
                            <i class="bi me-1" :class="item.icon" aria-hidden="true" />
                            {{ item.label }}
                        </RouterLink>
                    </li>
                </ul>
            </nav>
        </header>

        <main class="flex-grow-1 py-4">
            <div class="container">
                <RouterView />
            </div>
        </main>

        <footer class="bg-white border-top py-3">
            <div class="container small text-body-secondary">
                Consultora DH — portal de clientes.
            </div>
        </footer>
    </div>
</template>