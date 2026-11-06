import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The install invitation, exercised through the real component.
 *
 * The composable keeps its state at module scope, so each scenario loads a
 * fresh module generation (`vi.resetModules()` and dynamic imports) to prove
 * the behaviour a browser would see on a first load.
 */

const ANDROID_UA =
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';
const IOS_UA =
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const DESKTOP_UA =
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

const DISMISSED_KEY = 'cdh:pwa-banner-dismissed';
const TEMP_DISMISSED_KEY = 'cdh:pwa-banner-temp-dismissed';

interface LoginStack {
    wrapper: any;
    router: any;
    mount: typeof import('@vue/test-utils').mount;
    flushPromises: typeof import('@vue/test-utils').flushPromises;
    nextTick: typeof import('vue').nextTick;
}

function setUserAgent(userAgent: string, platformName = 'Linux', maxTouchPoints = 0): void {
    Object.defineProperty(window.navigator, 'userAgent', { configurable: true, value: userAgent });
    Object.defineProperty(window.navigator, 'platform', { configurable: true, value: platformName });
    Object.defineProperty(window.navigator, 'maxTouchPoints', {
        configurable: true,
        value: maxTouchPoints,
    });
}

function setStandalone(matches: boolean): void {
    const mediaQuery = {
        matches,
        media: '(display-mode: standalone)',
        onchange: null,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
        addListener: () => undefined,
        removeListener: () => undefined,
        dispatchEvent: () => true,
    };

    Object.defineProperty(window, 'matchMedia', {
        configurable: true,
        writable: true,
        value: vi.fn(() => mediaQuery),
    });
}

/**
 * A captured `beforeinstallprompt`.
 *
 * `prompt()` is a spy so the test observes that the native action really ran,
 * rather than only that a button exists.
 */
function dispatchInstallPrompt(
    outcome: 'accepted' | 'dismissed' = 'accepted',
): { prompt: ReturnType<typeof vi.fn> } {
    const prompt = vi.fn(async () => undefined);

    const event = new Event('beforeinstallprompt') as Event & Record<string, unknown>;
    Object.assign(event, {
        platforms: ['web'],
        prompt,
        userChoice: Promise.resolve({ outcome, platform: 'web' }),
    });

    window.dispatchEvent(event);

    return { prompt };
}

async function mountLogin(): Promise<LoginStack> {
    const [testUtils, vue, pinia, routerModule, page] = await Promise.all([
        import('@vue/test-utils'),
        import('vue'),
        import('pinia'),
        import('vue-router'),
        import('@/pages/auth/LoginPage.vue'),
    ]);

    const LoginPage = page.default;
    const empty = { render: () => null };

    const router = routerModule.createRouter({
        history: routerModule.createMemoryHistory(),
        routes: [
            { path: '/login', name: 'login', component: LoginPage },
            { path: '/', name: 'home', component: empty },
            { path: '/other', name: 'other', component: empty },
        ],
    });

    await router.push('/login');
    await router.isReady();

    const piniaInstance = pinia.createPinia();
    pinia.setActivePinia(piniaInstance);

    const wrapper = testUtils.mount(LoginPage, {
        global: { plugins: [piniaInstance, router] },
    });

    await testUtils.flushPromises();

    return {
        wrapper,
        router,
        mount: testUtils.mount,
        flushPromises: testUtils.flushPromises,
        nextTick: vue.nextTick,
    };
}

function offer(wrapper: any): any {
    return wrapper.find('[data-testid="pwa-install-offer"]');
}

function buttonByText(wrapper: any, label: string): any {
    return wrapper.findAll('button').find((button: any) => button.text().includes(label));
}

beforeEach(() => {
    vi.resetModules();
    window.localStorage.clear();
    window.sessionStorage.clear();
    setUserAgent(DESKTOP_UA);
    setStandalone(false);
});

afterEach(() => {
    delete (window.navigator as unknown as Record<string, unknown>).standalone;
});

describe('install invitation', () => {
    it('shows the native button when beforeinstallprompt was captured (Android)', async () => {
        setUserAgent(ANDROID_UA);
        const { wrapper } = await mountLogin();
        const { prompt } = dispatchInstallPrompt('accepted');
        await wrapper.vm.$nextTick();

        expect(offer(wrapper).exists()).toBe(true);
        expect(buttonByText(wrapper, 'Instalar aplicación')).toBeTruthy();
        expect(wrapper.text()).not.toContain('En Android');

        await buttonByText(wrapper, 'Instalar aplicación').trigger('click');
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(prompt).toHaveBeenCalledTimes(1);
        expect(offer(wrapper).exists()).toBe(false);
    });

    it('shows Android instructions when there is no native event', async () => {
        setUserAgent(ANDROID_UA);
        const { wrapper } = await mountLogin();

        expect(offer(wrapper).exists()).toBe(true);
        expect(wrapper.text()).toContain('En Android');
        expect(buttonByText(wrapper, 'Instalar aplicación')).toBeUndefined();
        expect(wrapper.text()).not.toContain('En iOS');
    });

    it('shows iOS instructions on iOS', async () => {
        setUserAgent(IOS_UA, 'iPhone', 5);
        const { wrapper } = await mountLogin();

        expect(offer(wrapper).exists()).toBe(true);
        expect(wrapper.text()).toContain('En iOS');
        expect(buttonByText(wrapper, 'Instalar aplicación')).toBeUndefined();
        expect(wrapper.text()).not.toContain('En Android');
    });

    it('shows desktop instructions on desktop', async () => {
        setUserAgent(DESKTOP_UA, 'Win32', 0);
        const { wrapper } = await mountLogin();

        expect(offer(wrapper).exists()).toBe(true);
        expect(wrapper.text()).toContain('desde el menú de su navegador');
        expect(buttonByText(wrapper, 'Instalar aplicación')).toBeUndefined();
        expect(wrapper.text()).not.toContain('En Android');
        expect(wrapper.text()).not.toContain('En iOS');
    });

    it('hides the whole invitation when "Ahora no" is clicked in native mode', async () => {
        setUserAgent(ANDROID_UA);
        const { wrapper } = await mountLogin();
        dispatchInstallPrompt('dismissed');
        await wrapper.vm.$nextTick();

        expect(offer(wrapper).exists()).toBe(true);

        await buttonByText(wrapper, 'Ahora no').trigger('click');
        await wrapper.vm.$nextTick();

        expect(offer(wrapper).exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Instalar aplicación');
    });

    it('hides the whole invitation when "Ahora no" is clicked in manual mode', async () => {
        setUserAgent(ANDROID_UA);
        const { wrapper } = await mountLogin();

        expect(offer(wrapper).exists()).toBe(true);

        await buttonByText(wrapper, 'Ahora no').trigger('click');
        await wrapper.vm.$nextTick();

        expect(offer(wrapper).exists()).toBe(false);
        expect(wrapper.text()).not.toContain('En Android');
    });

    it('does not bring the invitation back after navigating and remounting in the same session', async () => {
        setUserAgent(ANDROID_UA);
        const first = await mountLogin();

        await buttonByText(first.wrapper, 'Ahora no').trigger('click');
        await first.wrapper.vm.$nextTick();
        expect(offer(first.wrapper).exists()).toBe(false);

        await first.router.push('/other');
        await first.flushPromises();
        first.wrapper.unmount();

        // Same module generation: the reactive dismissal is shared and survives
        // the component being recreated.
        const second = await mountLogin();
        expect(offer(second.wrapper).exists()).toBe(false);
    });

    it('seeds the reactive dismissal from sessionStorage on a fresh module load', async () => {
        window.sessionStorage.setItem(TEMP_DISMISSED_KEY, '1');
        setUserAgent(ANDROID_UA);

        const { wrapper } = await mountLogin();

        expect(offer(wrapper).exists()).toBe(false);
    });

    it('offers the invitation again in a new session when only temporary dismissal happened', async () => {
        setUserAgent(ANDROID_UA);
        const first = await mountLogin();

        await buttonByText(first.wrapper, 'Ahora no').trigger('click');
        await first.wrapper.vm.$nextTick();
        expect(offer(first.wrapper).exists()).toBe(false);
        first.wrapper.unmount();

        // New browsing session: a fresh module generation and empty session
        // storage, with no permanent dismissal.
        vi.resetModules();
        window.sessionStorage.clear();
        window.localStorage.clear();

        const second = await mountLogin();
        expect(offer(second.wrapper).exists()).toBe(true);
    });

    it('shows no invitation at all when running standalone', async () => {
        setUserAgent(ANDROID_UA);
        setStandalone(true);

        const { wrapper } = await mountLogin();

        expect(offer(wrapper).exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Ahora no');
    });

    it('shows no invitation when the permanent dismissal is stored', async () => {
        window.localStorage.setItem(DISMISSED_KEY, '1');
        setUserAgent(ANDROID_UA);

        const { wrapper } = await mountLogin();

        expect(offer(wrapper).exists()).toBe(false);
    });

    it('keeps the dismissal hidden when the native prompt arrives after it', async () => {
        setUserAgent(ANDROID_UA);
        const { wrapper } = await mountLogin();

        await buttonByText(wrapper, 'Ahora no').trigger('click');
        await wrapper.vm.$nextTick();

        dispatchInstallPrompt('accepted');
        await wrapper.vm.$nextTick();

        expect(offer(wrapper).exists()).toBe(false);
        expect(buttonByText(wrapper, 'Instalar aplicación')).toBeUndefined();
    });
});
