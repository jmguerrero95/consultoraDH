import { config } from '@vue/test-utils';
import { beforeEach, vi } from 'vitest';

import { resetCsrfState } from '@/services/http';

/**
 * Test environment setup.
 *
 * jsdom does not implement the browser APIs the application touches during a
 * test, so the minimum surface is stubbed here rather than in every spec.
 */

// jsdom has no layout engine and no scrolling, so the router's scroll
// behaviour is stubbed rather than reported as an error on every navigation.
vi.stubGlobal('scrollTo', vi.fn());

// `document.cookie` is read by the HTTP client for the CSRF token.
beforeEach(() => {
    document.cookie = '';

    // The HTTP client caches the CSRF handshake at module level, so it has to
    // be reset between tests or one test's handshake hides another's request.
    resetCsrfState();

    vi.stubGlobal(
        'fetch',
        vi.fn(async () =>
            new Response(JSON.stringify({}), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }),
        ),
    );
});

// A real router is not needed: components are exercised in isolation, and the
// navigation guard has its own spec with a memory history.
config.global.stubs = {
    RouterLink: {
        template: '<a :href="to"><slot /></a>',
        props: ['to'],
    },
    RouterView: true,
};
