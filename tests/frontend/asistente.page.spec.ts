import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createTestingPinia } from '@pinia/testing';
import { createRouter, createWebHistory } from 'vue-router';
import AsistenteIndex from '@/pages/Asistente/Index.vue';

vi.mock('axios', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
  },
}));

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/asistente', name: 'asistente', component: AsistenteIndex },
  ],
});

describe('Asistente Page', () => {
  let wrapper: ReturnType<typeof mount>;

  beforeEach(() => {
    wrapper = mount(AsistenteIndex, {
      global: {
        plugins: [
          createTestingPinia({ createSpy: vi.fn }),
          router,
        ],
      },
    });
  });

  it('renders the page title', () => {
    expect(wrapper.text()).toContain('Asistente de IA');
  });

  it('has message input and send button', () => {
    const input = wrapper.find('input[type="text"]');
    const button = wrapper.find('button[type="submit"]');

    expect(input.exists()).toBe(true);
    expect(button.exists()).toBe(true);
    expect(button.text()).toBe('Enviar');
  });

  it('disables send button when input is empty', () => {
    const button = wrapper.find('button[type="submit"]');
    expect(button.attributes('disabled')).toBeDefined();
  });

  it('enables send button when input has content', async () => {
    const input = wrapper.find('input[type="text"]');
    const button = wrapper.find('button[type="submit"]');

    await input.setValue('Hola');
    expect(button.attributes('disabled')).toBeUndefined();
  });

  it('shows loading state when sending', async () => {
    const input = wrapper.find('input[type="text"]');
    const button = wrapper.find('button[type="submit"]');

    await input.setValue('Test message');
    await wrapper.find('form').trigger('submit.prevent');

    // Button should show loading text
    expect(button.text()).toContain('Enviando...');
  });
});