import { fileURLToPath, URL } from 'node:url';

import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig, loadEnv } from 'vite';

/**
 * Consultora DH - front-end build configuration.
 *
 * The dev server binds to 0.0.0.0 so the bundle is reachable both from the
 * Windows browser and from the application container. Assets are served from a
 * different port than the document, which is allowed: same site, so the session
 * cookie still travels with the API requests, and the API itself is called on
 * the application origin, so no CORS is involved.
 */
function resolveOrigin(value: string | undefined, fallback: string): string {
    try {
        return value === undefined ? fallback : new URL(value).origin;
    } catch {
        return fallback;
    }
}

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), ['VITE_', 'APP_URL']);

    const port = Number(env.VITE_PORT ?? 5173);

    // Bind mounts that do not deliver inotify events (WSL 9p, Docker Desktop
    // gRPC-FUSE, network shares) need polling for hot reloading to work.
    const usePolling = env.VITE_USE_POLLING === '1' || env.VITE_USE_POLLING === 'true';

    // The server listens on every interface inside the container, but the URL
    // handed to the browser must be one the browser can actually open. Without
    // this, the injected asset URLs and public/hot would advertise 0.0.0.0.
    const publicOrigin = `http://localhost:${port}`;

    // The document is served from the application origin, so the development
    // server has to accept cross origin requests for the compiled modules.
    //
    // The origin is reflected rather than pinned: the browser may be reaching
    // the application through localhost, a LAN address, a service name on the
    // Docker network, or a port forward, and pinning one of them makes the dev
    // server fail for the others. This is safe for a development asset server
    // because no cookies are involved (`credentials: false`) and the server only
    // ever exists on the developer's machine.
    void resolveOrigin(env.APP_URL, 'http://localhost:8080');

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.ts'],
                refresh: true,
            }),
            vue({
                template: {
                    compilerOptions: {
                        // Resolve components by their registered name so that
                        // templates keep working with the runtime compiler too.
                        isCustomElement: (tag) => tag.startsWith('bootstrap-'),
                    },
                },
            }),
        ],

        resolve: {
            alias: {
                '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            },
        },

        server: {
            host: '0.0.0.0',
            port,
            strictPort: true,
            origin: publicOrigin,
            cors: {
                origin: true,
                credentials: false,
            },
            hmr: {
                host: 'localhost',
                protocol: 'ws',
            },
            watch: {
                ignored: ['**/storage/framework/views/**', '**/vendor/**'],
                usePolling,
                interval: 400,
            },
        },

        build: {
            target: 'es2022',
            sourcemap: false,
            chunkSizeWarningLimit: 700,
        },
    };
});
