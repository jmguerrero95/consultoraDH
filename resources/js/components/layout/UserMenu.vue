<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import { useRouter } from 'vue-router';

import AppModal from '@/components/ui/AppModal.vue';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/**
 * Account menu: current user, current role, profile link and sign out.
 *
 * Signing out asks for confirmation, because losing a half filled form is
 * worse than one extra click.
 */
const auth = useAuthStore();
const toasts = useToastStore();
const router = useRouter();

const menu = useTemplateRef<HTMLDivElement>('menu');
const trigger = useTemplateRef<HTMLButtonElement>('trigger');
const open = ref(false);
const confirmOpen = ref(false);
const busy = ref(false);

function toggle(): void {
    open.value = !open.value;
}

function close(): void {
    open.value = false;
    trigger.value?.focus();
}

function onDocumentPointerDown(event: PointerEvent): void {
    const target = event.target as Node | null;

    if (target !== null && !menu.value?.contains(target) && !trigger.value?.contains(target)) {
        open.value = false;
    }
}

function onDocumentKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && open.value) {
        event.stopPropagation();
        close();
    }
}

async function confirmLogout(): Promise<void> {
    busy.value = true;

    try {
        await auth.logout();

        toasts.clear();
        toasts.success('Sesión cerrada', 'Ha cerrado la sesión de forma segura.');

        confirmOpen.value = false;
        open.value = false;

        await router.push({ name: 'login' });
    } finally {
        busy.value = false;
    }
}

onMounted(() => {
    document.addEventListener('pointerdown', onDocumentPointerDown);
    document.addEventListener('keydown', onDocumentKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocumentPointerDown);
    document.removeEventListener('keydown', onDocumentKeydown);
});
</script>

<template>
    <div class="cdh-dropdown">
        <button
            id="user-menu-trigger"
            ref="trigger"
            type="button"
            class="cdh-user"
            aria-haspopup="menu"
            :aria-expanded="open"
            aria-controls="user-menu"
            @click="toggle"
        >
            <span class="cdh-avatar" aria-hidden="true">{{ auth.user?.initials ?? '—' }}</span>

            <span class="cdh-user__meta">
                <span class="cdh-user__name">{{ auth.user?.name }}</span>
                <span class="cdh-user__role">{{ auth.user?.primary_role ?? 'Sin rol' }}</span>
            </span>

            <i class="bi bi-chevron-down cdh-text-subtle" aria-hidden="true" />
        </button>

        <div
            v-show="open"
            id="user-menu"
            ref="menu"
            class="cdh-dropdown__menu"
            role="menu"
            aria-labelledby="user-menu-trigger"
        >
            <div class="cdh-dropdown__header">
                <p class="cdh-dropdown__name">{{ auth.user?.name }}</p>
                <p class="cdh-dropdown__email">{{ auth.user?.email }}</p>
            </div>

            <RouterLink to="/profile" class="cdh-dropdown__item" role="menuitem" @click="close">
                <i class="bi bi-person" aria-hidden="true" />
                <span>Mi perfil</span>
            </RouterLink>

            <button
                type="button"
                class="cdh-dropdown__item cdh-dropdown__item--danger"
                role="menuitem"
                @click="confirmOpen = true"
            >
                <i class="bi bi-box-arrow-right" aria-hidden="true" />
                <span>Cerrar sesión</span>
            </button>
        </div>
    </div>

    <AppModal
        :open="confirmOpen"
        title="¿Desea cerrar la sesión?"
        confirm-label="Cerrar sesión"
        :busy="busy"
        @confirm="confirmLogout"
        @cancel="confirmOpen = false"
    >
        Se cerrará la sesión en este navegador. Cualquier trabajo sin guardar se perderá.
    </AppModal>
</template>
