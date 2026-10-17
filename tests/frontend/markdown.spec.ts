import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import SafeMarkdown from '@/components/ui/SafeMarkdown.vue';
import { renderMarkdown, safeUrl, stripMarkdown } from '@/utils/markdown';

/**
 * The Markdown renderer and its plain-text twin.
 *
 * ## What is actually being asserted
 *
 * The input is a model's answer, which is untrusted, and the output is bound to
 * the DOM with `v-html`. So the assertions are less about pretty formatting than
 * about the boundary: no input may produce a tag, an attribute or a scheme the
 * renderer did not write itself.
 */

describe('renderMarkdown — safety', () => {
    it('escapes raw HTML rather than executing it', () => {
        const html = renderMarkdown('<script>alert(1)</script> <img src=x onerror=alert(2)>');

        expect(html).not.toContain('<script');
        expect(html).not.toContain('<img');
        expect(html).toContain('&lt;script&gt;');
    });

    it('refuses a javascript: link and leaves the text visible', () => {
        const html = renderMarkdown('[haz clic](javascript:alert(1))');

        expect(html).not.toContain('href="javascript:');
        expect(html).not.toContain('<a ');
        expect(html).toContain('haz clic');
    });

    it('refuses a data: link', () => {
        const html = renderMarkdown('[x](data:text/html,<b>y</b>)');

        expect(html).not.toContain('href="data:');
    });

    it('rejects a protocol-relative URL but keeps same-origin paths', () => {
        expect(safeUrl('//evil.example/x')).toBeNull();
        expect(safeUrl('/clientes/5')).toBe('/clientes/5');
        expect(safeUrl('#seccion')).toBe('#seccion');
        expect(safeUrl('https://banrep.gov.co')).toBe('https://banrep.gov.co');
        expect(safeUrl('mailto:hola@example.com')).toBe('mailto:hola@example.com');
    });

    it('renders a real link with noopener and target blank', () => {
        const html = renderMarkdown('Ver [la fuente](https://www.banrep.gov.co/inflacion).');
        const anchor = html.match(/<a [^>]*>/)?.[0] ?? '';

        expect(anchor).toContain('href="https://www.banrep.gov.co/inflacion"');
        expect(anchor).toContain('rel="noopener noreferrer"');
        expect(anchor).toContain('target="_blank"');
    });
});

describe('renderMarkdown — formatting', () => {
    it('renders headings, emphasis and inline code', () => {
        const html = renderMarkdown('## Resumen\n\nLa cartera **vencida** es `$45M`.');

        expect(html).toContain('<h2>Resumen</h2>');
        expect(html).toContain('<strong>vencida</strong>');
        expect(html).toContain('<code>$45M</code>');
    });

    it('renders ordered and unordered lists', () => {
        const html = renderMarkdown('1. Primero\n2. Segundo\n\n- Uno\n- Dos');

        expect(html).toContain('<ol>');
        expect(html).toContain('<li>Primero</li>');
        expect(html).toContain('<ul>');
        expect(html).toContain('<li>Uno</li>');
    });

    it('keeps markdown inside a code fence literal', () => {
        const html = renderMarkdown('```\n**no** es negrita\n```');

        expect(html).toContain('<pre><code>**no** es negrita</code></pre>');
        expect(html).not.toContain('<strong>');
    });

    it('turns a bare URL into a link', () => {
        const html = renderMarkdown('Fuente: https://open-meteo.com/');

        expect(html).toContain('<a href="https://open-meteo.com/"');
    });
});

describe('stripMarkdown', () => {
    it('reads prose without the punctuation', () => {
        const spoken = stripMarkdown('## Cartera\n\nLa **deuda** es `$45M`.\n\n- Uno\n- Dos');

        expect(spoken).not.toContain('*');
        expect(spoken).not.toContain('#');
        expect(spoken).not.toContain('`');
        expect(spoken).toContain('deuda');
        expect(spoken).toContain('$45M');
        expect(spoken).toContain('Uno');
    });

    it('keeps a plain sentence exactly as written', () => {
        const sentence = 'La cartera vencida asciende a 45 millones.';

        expect(stripMarkdown(sentence)).toBe(sentence);
    });
});

describe('SafeMarkdown component', () => {
    it('renders formatted content and never injects a script element', () => {
        const wrapper = mount(SafeMarkdown, {
            props: { content: '**Hola** <script>window.x = 1</script>' },
        });

        expect(wrapper.find('strong').text()).toBe('Hola');
        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.html()).not.toContain('<script');
    });
});
