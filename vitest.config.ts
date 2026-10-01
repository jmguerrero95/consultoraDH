import { fileURLToPath, URL } from 'node:url';

import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

/**
 * Consultora DH - unit test configuration.
 *
 * Kept separate from vite.config.ts so the Laravel plugin, which only makes
 * sense for the application build, is not loaded while testing.
 */
export default defineConfig({
    plugins: [vue()],

    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },

    test: {
        environment: 'jsdom',
        globals: false,
        include: ['tests/frontend/**/*.spec.ts'],
        setupFiles: ['tests/frontend/setup.ts'],
        restoreMocks: true,
        coverage: {
            provider: 'v8',
            include: ['resources/js/**/*.{ts,vue}'],
            exclude: ['resources/js/env.d.ts', 'resources/js/app.ts'],
        },
    },
});
