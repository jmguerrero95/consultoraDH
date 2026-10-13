import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

import AttachmentPicker from '@/components/assistant/AttachmentPicker.vue';
import { ai } from '@/services/ai';

/**
 * The attachment control.
 *
 * The behaviour worth protecting is accessibility and honesty: a real file input
 * (so a keyboard and a phone can both use it), and no silent failures when a
 * file is refused.
 */

function xlsxFile(name = 'cartera.xlsx'): File {
    return new File(['bytes'], name, {
        type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    });
}

describe('AttachmentPicker', () => {
    it('is a real file input, so a keyboard and a phone can both reach it', () => {
        const wrapper = mount(AttachmentPicker);
        const input = wrapper.get('[data-testid="attachment-input"]');

        // Not a div with a click handler: that is unreachable by keyboard and
        // is not announced as a control.
        expect(input.element.tagName).toBe('INPUT');
        expect(input.attributes('type')).toBe('file');
    });

    it('declares the formats the server accepts', () => {
        const accept = mount(AttachmentPicker).get('[data-testid="attachment-input"]').attributes('accept') ?? '';

        for (const format of ['.pdf', '.docx', '.xlsx', '.csv', '.txt', '.md']) {
            expect(accept).toContain(format);
        }
    });

    it('reports the chosen file upwards', async () => {
        const wrapper = mount(AttachmentPicker);
        const file = xlsxFile();

        // The change event the browser raises once a file is chosen.
        const input = wrapper.get('[data-testid="attachment-input"]').element as HTMLInputElement;
        Object.defineProperty(input, 'files', { value: [file], configurable: true });

        await wrapper.get('[data-testid="attachment-input"]').trigger('change');

        expect(wrapper.emitted('choose')).toEqual([[file]]);
        expect(wrapper.get('[data-testid="attachment-name"]').text()).toBe('cartera.xlsx');
    });

    it('shows nothing chosen when the dialog is dismissed', async () => {
        const wrapper = mount(AttachmentPicker);

        Object.defineProperty(wrapper.get('[data-testid="attachment-input"]').element, 'files', {
            value: [],
            configurable: true,
        });

        await wrapper.get('[data-testid="attachment-input"]').trigger('change');

        expect(wrapper.emitted('choose')).toBeUndefined();
        expect(wrapper.find('[data-testid="attachment-name"]').exists()).toBe(false);
    });

    it('cannot be used while something else is in flight', () => {
        expect(mount(AttachmentPicker, { props: { busy: true } }).get('[data-testid="attachment-input"]').attributes('disabled')).toBeDefined();
        expect(mount(AttachmentPicker, { props: { disabled: true } }).get('[data-testid="attachment-input"]').attributes('disabled')).toBeDefined();
    });
});

describe('ai.uploadTask', () => {
    it('sends multipart and only omits the conversation when there is none', async () => {
        const spy = vi.spyOn(ai, 'uploadTask').mockResolvedValue({
            task: {
                id: 1,
                kind: 'document_ingest',
                status: 'queued',
                progress: 0,
                stage: 'En cola',
                name: 'cartera.xlsx',
                result: null,
                error: null,
                attempts: 0,
                finished: false,
                recoverable: false,
                conversation_id: null,
                created_at: null,
                finished_at: null,
            },
        });

        await ai.uploadTask(xlsxFile(), 7);
        expect(spy).toHaveBeenCalledWith(expect.any(File), 7);

        await ai.uploadTask(xlsxFile(), null);
        expect(spy).toHaveBeenLastCalledWith(expect.any(File), null);
    });
});
