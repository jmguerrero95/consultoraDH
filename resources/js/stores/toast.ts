import { defineStore } from 'pinia';
import { ref } from 'vue';

export type ToastVariant = 'success' | 'danger' | 'warning' | 'info';

export interface Toast {
    id: number;
    title: string;
    message: string | null;
    variant: ToastVariant;
    /** Milliseconds before the toast disappears on its own. */
    timeout: number;
}

const DEFAULT_TIMEOUT = 6000;

/**
 * Transient notifications.
 *
 * A tiny hand rolled store instead of a notification library: the application
 * needs a queue and a dismiss button, and nothing more.
 */
export const useToastStore = defineStore('toast', () => {
    const toasts = ref<Toast[]>([]);
    const nextId = ref(1);
    const timers = new Map<number, ReturnType<typeof setTimeout>>();

    function dismiss(id: number): void {
        const timer = timers.get(id);

        if (timer !== undefined) {
            clearTimeout(timer);
            timers.delete(id);
        }

        toasts.value = toasts.value.filter((toast) => toast.id !== id);
    }

    function push(
        variant: ToastVariant,
        title: string,
        message: string | null = null,
        timeout: number = DEFAULT_TIMEOUT,
    ): number {
        const id = nextId.value;

        nextId.value += 1;

        toasts.value = [...toasts.value, { id, title, message, variant, timeout }];

        if (timeout > 0) {
            timers.set(
                id,
                setTimeout(() => dismiss(id), timeout),
            );
        }

        return id;
    }

    const success = (title: string, message: string | null = null): number =>
        push('success', title, message);

    const error = (title: string, message: string | null = null): number =>
        push('danger', title, message);

    const warning = (title: string, message: string | null = null): number =>
        push('warning', title, message);

    const info = (title: string, message: string | null = null): number =>
        push('info', title, message);

    /** Remove everything, e.g. when a session ends. */
    function clear(): void {
        timers.forEach((timer) => clearTimeout(timer));
        timers.clear();
        toasts.value = [];
    }

    return { toasts, push, success, error, warning, info, dismiss, clear };
});
