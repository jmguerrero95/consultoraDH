import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import AppWordmark from '@/components/brand/AppWordmark.vue';
import AppEmptyState from '@/components/ui/AppEmptyState.vue';
import AppFormField from '@/components/ui/AppFormField.vue';
import AppLoading from '@/components/ui/AppLoading.vue';

/** The brand joins its two words with a non breaking space on purpose. */
function plainText(wrapper: { text(): string }): string {
    return wrapper.text().replace(/\u00a0/g, ' ');
}

describe('AppWordmark', () => {
    it('renders the Consultora DH brand', () => {
        expect(plainText(mount(AppWordmark))).toContain('Consultora DH');
    });

    it('shows the descriptor line by default and hides it on request', () => {
        expect(mount(AppWordmark).text()).toContain('Gestión administrativa');

        expect(mount(AppWordmark, { props: { hideDescriptor: true } }).text()).not.toContain(
            'Gestión administrativa',
        );
    });

    it('exposes the initials of the brand as decorative', () => {
        const mark = mount(AppWordmark).get('.cdh-wordmark__mark');

        expect(mark.attributes('aria-hidden')).toBe('true');
        expect(mark.text()).toBe('CD');
    });
});

describe('AppEmptyState', () => {
    it('renders the title and the explanatory slot content', () => {
        const wrapper = mount(AppEmptyState, {
            props: { title: 'Todavía no hay información' },
            slots: { default: 'Los módulos aparecerán aquí.' },
        });

        expect(wrapper.get('.cdh-empty__title').text()).toBe('Todavía no hay información');
        expect(wrapper.get('.cdh-empty__text').text()).toBe('Los módulos aparecerán aquí.');
    });

    it('hides the action button when no label is given', () => {
        const wrapper = mount(AppEmptyState, { props: { title: 'Vacío' } });

        expect(wrapper.find('button').exists()).toBe(false);
    });
});

describe('AppLoading', () => {
    it('announces the wait without interrupting', () => {
        const wrapper = mount(AppLoading, { props: { label: 'Cargando información…' } });

        expect(wrapper.attributes('role')).toBe('status');
        expect(wrapper.attributes('aria-live')).toBe('polite');
        expect(wrapper.text()).toContain('Cargando información…');
    });

    it('hides the spinner from assistive technology', () => {
        expect(mount(AppLoading).get('.cdh-spinner').attributes('aria-hidden')).toBe('true');
    });
});

describe('AppFormField', () => {
    it('binds the label to the control', () => {
        const wrapper = mount(AppFormField, {
            props: { label: 'Nombre completo', name: 'name', modelValue: '' },
        });

        const input = wrapper.get('input');
        const label = wrapper.get('label');

        expect(label.attributes('for')).toBe(input.attributes('id'));
    });

    it('associates the hint and the error with the control', () => {
        const wrapper = mount(AppFormField, {
            props: {
                label: 'Correo electrónico',
                name: 'email',
                modelValue: '',
                hint: 'Su correo institucional.',
                error: 'El correo electrónico es obligatorio.',
            },
        });

        const describedBy = wrapper.get('input').attributes('aria-describedby') ?? '';

        expect(describedBy).toContain(wrapper.get('.cdh-form-hint').attributes('id'));
        expect(describedBy).toContain(wrapper.get('.cdh-form-error span').element.parentElement?.id ?? '');
        expect(wrapper.get('input').attributes('aria-invalid')).toBe('true');
    });

    it('emits the new value as the user types', async () => {
        const wrapper = mount(AppFormField, {
            props: { label: 'Nombre', name: 'name', modelValue: '' },
        });

        await wrapper.get('input').setValue('Ana Restrepo');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['Ana Restrepo']);
    });

    it('marks a required control for assistive technology', () => {
        const wrapper = mount(AppFormField, {
            props: { label: 'Nombre', name: 'name', modelValue: '', required: true },
        });

        expect(wrapper.get('input').attributes('aria-required')).toBe('true');
        // The asterisk is decorative; the word is what a screen reader reads.
        expect(wrapper.text()).toContain('(obligatorio)');
    });
});
