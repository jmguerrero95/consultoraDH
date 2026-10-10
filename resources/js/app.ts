import { createPinia } from 'pinia';
import { createApp } from 'vue';

import App from '@/App.vue';
import { router } from '@/router';

/**
 * Consultora DH - front-end entry point.
 *
 * The document is served by Laravel as a shell with no user data; the session is
 * resolved from the API by the router guard, so a hard refresh, a deep link and
 * an expired session all behave identically.
 */
const app = createApp(App);

app.use(createPinia());
app.use(router);

// Register service worker for PWA
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then((registration) => {
                console.log('Service Worker registered:', registration.scope);
            })
            .catch((error) => {
                console.error('Service Worker registration failed:', error);
            });
    });
}

app.mount('#app');