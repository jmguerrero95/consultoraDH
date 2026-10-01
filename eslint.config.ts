import js from '@eslint/js';
import vue from 'eslint-plugin-vue';
import globals from 'globals';
import tseslint from 'typescript-eslint';

/**
 * Consultora DH - lint configuration.
 *
 * Type aware, because the rules that matter here (no floating promises, no
 * unsafe `any`) only work when the checker can see the types.
 */
export default tseslint.config(
    {
        ignores: [
            'node_modules/**',
            'vendor/**',
            'public/**',
            'storage/**',
            'bootstrap/cache/**',
            'playwright-report/**',
            'test-results/**',
        ],
    },

    js.configs.recommended,
    ...tseslint.configs.recommended,
    ...vue.configs['flat/recommended'],

    {
        files: ['**/*.{ts,vue}'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            // The application is a browser application; the tests run in jsdom.
            globals: {
                ...globals.browser,
                ...globals.es2022,
            },
            parserOptions: {
                parser: tseslint.parser,
            },
        },
        rules: {
            // The application is fully typed; `any` is never justified here.
            '@typescript-eslint/no-explicit-any': 'error',
            '@typescript-eslint/consistent-type-imports': [
                'error',
                { prefer: 'type-imports', fixStyle: 'separate-type-imports' },
            ],
            '@typescript-eslint/no-unused-vars': [
                'error',
                { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
            ],
            'no-console': ['warn', { allow: ['warn', 'error'] }],
            eqeqeq: ['error', 'smart'],
            'prefer-const': 'error',
        },
    },

    {
        // Single word component file names, as in the application code.
        files: ['resources/js/**/*.vue'],
        rules: {
            'vue/multi-word-component-names': 'off',
            'vue/max-attributes-per-line': 'off',
            'vue/singleline-html-element-content-newline': 'off',
            'vue/html-self-closing': 'off',
            'vue/attributes-order': 'off',
            'vue/html-indent': 'off',
            'vue/html-closing-bracket-newline': 'off',
            'vue/first-attribute-linebreak': 'off',
        },
    },

    {
        files: ['tests/frontend/**/*.ts'],
        rules: {
            // Tests deliberately push invalid payloads to prove they are refused.
            '@typescript-eslint/no-unused-expressions': 'off',
        },
    },

    {
        files: ['vite.config.ts', 'vitest.config.ts', 'playwright.config.ts', 'eslint.config.ts'],
        languageOptions: {
            // Build and test tooling legitimately runs in Node.
            globals: {
                ...globals.node,
            },
        },
        rules: {
            'no-console': 'off',
        },
    },
);
