import { createPinia } from 'pinia';
import { createApp } from 'vue';

import iconsFontUrl from 'bootstrap-icons/font/fonts/bootstrap-icons.woff2?url';

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

/**
 * Start fetching the icon font immediately.
 *
 * The icons come from Bootstrap Icons, whose stylesheet declares the font with
 * `font-display: block`: nothing is painted for an icon until its 134 KB file has
 * arrived, so the page used to appear with its labels but without its icons, and the
 * icons then popped in a moment later. The font is only discovered when the CSS is
 * parsed and its `url()` is read, which is too late for a file on the critical path.
 *
 * Declared here instead, so the browser opens the request at the same time as the
 * stylesheet. Vite rewrites the import to the same hashed file the CSS points at, so
 * the preload is used instead of being downloaded twice.
 */
function preloadIconFont(): void {
    const link = document.createElement('link');

    link.rel = 'preload';
    link.as = 'font';
    link.type = 'font/woff2';
    link.href = iconsFontUrl;
    link.crossOrigin = 'anonymous';

    document.head.append(link);
}

preloadIconFont();

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
