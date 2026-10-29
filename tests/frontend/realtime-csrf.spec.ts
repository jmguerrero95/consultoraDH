import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useRealtimeStore } from '@/stores/realtime';

/**
 * PERF-01-R1 — the realtime handshake sends the CSRF value Laravel expects.
 *
 * `csrfToken()` reads the decoded `XSRF-TOKEN` cookie. That is the value for
 * `X-XSRF-TOKEN` (Laravel decrypts it with the cookie key); `X-CSRF-TOKEN` is
 * the raw `csrf_token()` value, which this application deliberately no longer
 * renders into the document. Echo is mocked so the config it receives is the
 * subject of the test, not the socket.
 */

const harness = vi.hoisted(() => ({
    configs: [] as Array<Record<string, unknown>>,
    connected: [] as Array<() => void>,
}));

vi.mock('laravel-echo', () => ({
    default: class EchoMock {
        connector: unknown;

        constructor(config: Record<string, unknown>) {
            harness.configs.push(config);

            this.connector = {
                pusher: {
                    connection: {
                        bind: (event: string, callback: () => void) => {
                            if (event === 'connected') {
                                harness.connected.push(callback);
                            }
                        },
                    },
                },
            };
        }

        private() {
            return { listen: () => {}, stopListening: () => {} };
        }

        join() {
            return { listen: () => {}, here: () => {}, joining: () => {}, leaving: () => {} };
        }

        leave() {}

        disconnect() {}
    },
}));

vi.mock('pusher-js', () => ({
    default: class PusherMock {},
}));

beforeEach(() => {
    harness.configs.length = 0;
    harness.connected.length = 0;

    setActivePinia(createPinia());
});

describe('realtime CSRF header', () => {
    it('sends the decoded XSRF cookie in X-XSRF-TOKEN and keeps the auth endpoint', async () => {
        document.cookie = 'XSRF-TOKEN=abc%3Ddef%26ghi';

        const store = useRealtimeStore();
        const ready = store.initialize();

        await vi.waitFor(() => expect(harness.configs.length).toBe(1));

        harness.connected.forEach((callback) => callback());
        await ready;

        const config = harness.configs[0] as {
            authEndpoint: string;
            auth: { headers: Record<string, string> };
        };

        expect(config.authEndpoint).toBe('/broadcasting/auth');
        expect(config.auth.headers).toEqual({ 'X-XSRF-TOKEN': 'abc=def&ghi' });
        expect(config.auth.headers['X-CSRF-TOKEN']).toBeUndefined();
        expect(store.isConnected).toBe(true);
    });
});
