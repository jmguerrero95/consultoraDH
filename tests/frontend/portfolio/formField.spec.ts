import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import AppFormField from '@/components/ui/AppFormField.vue';

/**
 * The client and company forms are built entirely from this component, and the
 * select mode is the part a screen reader and a keyboard user depend on, so it is
 * worth pinning down rather than assuming it works.
 */
describe('AppFormField in select mode', () => {
    const options = [
        { value: 'EPS', label: 'EPS' },
        { value: 'CCF', label: 'Caja de Compensación Familiar' },
    ];

    it('renders a select with one option per choice', () => {
        const wrapper = mount(AppFormField, {
            props: {
                label: 'Tipo',
                name: 'tipo',
                modelValue: 'EPS',
                options,
            },
        });

        // `get` throws when the element is missing, so finding it is the assertion.
        expect(wrapper.get('select').element.tagName).toBe('SELECT');
        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.findAll('option')).toHaveLength(options.length);
    });

    it('adds the empty choice only when asked for it', () => {
        const withEmpty = mount(AppFormField, {
            props: {
                label: 'Entidad',
                name: 'entidad',
                modelValue: '',
                options,
                emptyLabel: 'Seleccione una entidad',
            },
        });

        expect(withEmpty.findAll('option')).toHaveLength(options.length + 1);
        expect(withEmpty.get('option[value=""]').text()).toBe('Seleccione una entidad');

        const without = mount(AppFormField, {
            props: { label: 'Tipo', name: 'tipo', modelValue: 'EPS', options },
        });

        expect(without.findAll('option')).toHaveLength(options.length);
    });

    it('marks the current value as selected', () => {
        const wrapper = mount(AppFormField, {
            props: { label: 'Tipo', name: 'tipo', modelValue: 'CCF', options },
        });

        expect(wrapper.get('select').element.value).toBe('CCF');
    });

    it('emits the chosen value on change', async () => {
        const wrapper = mount(AppFormField, {
            props: { label: 'Tipo', name: 'tipo', modelValue: 'EPS', options },
        });

        await wrapper.get('select').setValue('CCF');

        expect(wrapper.emitted('update:modelValue')).toEqual([['CCF']]);
    });

    it('keeps the label, hint and error wiring, and marks the invalid state', () => {
        const wrapper = mount(AppFormField, {
            props: {
                label: 'Tipo',
                name: 'tipo',
                modelValue: '',
                options,
                hint: 'Elige una de las cuatro.',
                error: 'Debe indicar el tipo.',
            },
        });

        const select = wrapper.get('select');
        const controlId = select.attributes('id');
        const describedBy = (select.attributes('aria-describedby') ?? '').split(' ');

        expect(wrapper.get('label').attributes('for')).toBe(controlId);
        expect(select.attributes('aria-invalid')).toBe('true');
        // The control is described by both the hint and the error, by id.
        expect(describedBy).toHaveLength(2);
        for (const id of describedBy) {
            expect(wrapper.find(`#${id}`).exists()).toBe(true);
        }
        // The invalid state belongs to the control, not to the wrapper.
        expect(select.classes()).toContain('is-invalid');
    });

    it('disables the control when asked', () => {
        const wrapper = mount(AppFormField, {
            props: { label: 'Tipo', name: 'tipo', modelValue: 'EPS', options, disabled: true },
        });

        expect(wrapper.get('select').attributes('disabled')).toBeDefined();
    });
});