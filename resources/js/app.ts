import { createPinia } from 'pinia';
import { createApp } from 'vue';

import App from '@/App.vue';
import { router } from '@/router';
import { usePWAInstallation } from '@/composables/usePWAInstallation';

/**
 * Consultora DH - front-end entry point.
 *
 * The document is served by Laravel as a shell with no user data; the session is
 * resolved from the API by the router guard, so a hard refresh, a deep link and
 * an expired session all behave identically.
 */
const app = createApp(App);

/**
 * Initialize PWA installation UX: capture the install prompt, detect standalone
 * mode, and expose the one install function the account menu calls.
 *
 * The composable keeps its state at module scope, so this call and the one in
 * `UserMenu.vue` observe the same captured prompt.
 */
function initializePWA(): void {
    const { install } = usePWAInstallation();

    ;(window as unknown as { consultoraDH?: { installPWA: () => Promise<boolean> } }).consultoraDH = {
        installPWA: install,
    };
}

initializePWA();

/**
 * Start fetching the icon font immediately.
 *
 * The icons come from Bootstrap Icons, whose stylesheet declares the font with
 * `font-display: block`: nothing is painted for an icon until its file has
 * arrived. The preload now lives in the document head (see `app.blade.php`), so
 * the browser discovers it while parsing the HTML instead of waiting for this
 * bundle to execute. Keeping a second, JavaScript-created preload here would add
 * a duplicate hint after the parser has already moved on.
 */

app.use(createPinia());
app.use(router);

/*
 * Register the service worker for the PWA.
 *
 * Only in a production build. During development the document is served by Vite and
 * every asset comes from the dev server, while a registered worker keeps answering
 * navigations and cached build assets from an earlier session — which is how a
 * developer ends up looking at a page whose bundle is not the one on disk.
 */
if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => {
        navigator.serviceWorker
            .register('/sw.js')
            .catch((error) => {
                console.error('Service Worker registration failed:', error);
            });
    });
}

app.mount('#app');
