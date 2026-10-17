/**
 * A small, deliberately conservative Markdown renderer, and its plain-text twin.
 *
 * ## Why this exists instead of a library
 *
 * The assistant answers in Markdown — headings, bold, lists, code and, above
 * all, links to real sources — and the alternative to rendering it is showing
 * asterisks and brackets to a person who asked about their portfolio. Pulling in
 * `marked` plus `dompurify` would mean two new runtime dependencies, a bundle
 * almost as large as the assistant itself, and a version to keep patched, for a
 * grammar of six constructs. This file is that grammar and nothing else.
 *
 * ## The one rule: escape, then decorate
 *
 * The input is a model's answer, which is to say it is untrusted text. So it is
 * HTML-escaped *first*, and every construct below is applied to an already-inert
 * string. The only tags that can come out are the ones written literally in this
 * file: paragraphs, line breaks, emphasis, code, lists, headings, quotes, rules
 * and anchors. A `<script>`, an `onerror=` or an `<img onload=…>` in the answer
 * is escaped before any of it is looked at, so there is nothing left for the
 * browser to execute. That is also why `stripMarkdown` can be written without a
 * parser: it only removes punctuation.
 *
 * ## Links are the one place that can still bite
 *
 * A clickable URL is the single construct that survives escaping with a scheme
 * intact, so `safeUrl` admits only `http`, `https`, `mailto`, and same-origin
 * relative paths. `javascript:`, `data:`, `vbscript:` and protocol-relative
 * `//host` URLs are refused and left as visible text rather than silently
 * turned into anchors.
 */

const HTML_ESCAPES: Record<string, string> = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
};

/** Escape the five characters that can change an HTML document's meaning. */
export function escapeHtml(value: string): string {
    return value.replace(/[&<>"']/g, (character) => HTML_ESCAPES[character]);
}

/**
 * The scheme of a URL, or `null` when it must not become a link.
 *
 * Relative paths and fragments are allowed because they stay on this origin.
 * Everything else has to name `http`, `https` or `mailto` explicitly: a
 * protocol-relative `//example.com` is rejected on purpose, because it is an
 * external link dressed up as a path.
 */
export function safeUrl(raw: string): string | null {
    const url = raw.trim();

    if (url === '') {
        return null;
    }

    if (url.startsWith('#') || (url.startsWith('/') && !url.startsWith('//') && !url.startsWith('/\\'))) {
        return url;
    }

    if (/^(https?:|mailto:)/i.test(url)) {
        return url;
    }

    return null;
}

/**
 * Render Markdown to HTML that is safe to bind with `v-html`.
 *
 * `source` may contain anything; the result contains only the fixed set of tags
 * listed above, in whatever nesting the input's own structure asks for.
 */
export function renderMarkdown(source: string): string {
    if (source === '') {
        return '';
    }

    const normalized = source.replace(/\r\n?/g, '\n');

    // Code is pulled out before anything else and put back at the very end, so
    // that `*`, `_` and `[x](y)` inside a snippet are never read as formatting.
    //
    // The placeholder is a private-use codepoint, not a control character: it
    // cannot be produced by the escaping above, so it can never collide with
    // real content, and its own regex is not a control-character pattern.
    const code: string[] = [];
    const stash = (html: string): string => {
        code.push(html);

        return `\uE000${code.length - 1}\uE000`;
    };

    let text = escapeHtml(normalized);

    // Fenced blocks: ```lang\n … \n ```
    text = text.replace(/```[^\n`]*\n([\s\S]*?)```/g, (_match, body: string) =>
        stash(`<pre><code>${body.replace(/\n$/, '')}</code></pre>`),
    );

    // An unterminated fence at the end of the answer is still code, not a
    // paragraph that happens to start with backticks.
    text = text.replace(/```[^\n`]*\n([\s\S]*)$/g, (_match, body: string) =>
        stash(`<pre><code>${body.replace(/\n$/, '')}</code></pre>`),
    );

    // Inline spans: `…`
    text = text.replace(/`([^`\n]+)`/g, (_match, body: string) => stash(`<code>${body}</code>`));

    const html = renderBlocks(text);
    const restored = html.replace(/\uE000(\d+)\uE000/g, (_match, index: string) => code[Number(index)] ?? '');

    return restored;
}

/**
 * The block grammar. `text` is already escaped and still contains the `\uE000n\uE000`
 * placeholders that stand in for code.
 */
function renderBlocks(text: string): string {
    const lines = text.split('\n');
    const out: string[] = [];

    let mode: 'none' | 'ul' | 'ol' | 'quote' = 'none';
    let paragraph: string[] = [];

    const closeBlock = (): void => {
        if (mode === 'ul') {
            out.push('</ul>');
        } else if (mode === 'ol') {
            out.push('</ol>');
        } else if (mode === 'quote') {
            out.push('</blockquote>');
        }

        mode = 'none';
    };

    const flushParagraph = (): void => {
        if (paragraph.length > 0) {
            out.push(`<p>${paragraph.join('<br>')}</p>`);
            paragraph = [];
        }
    };

    for (const line of lines) {
        // A stashed code block on a line of its own is a block, not a paragraph.
        const codeOnly = line.match(/^\uE000(\d+)\uE000$/);

        if (codeOnly) {
            flushParagraph();
            closeBlock();
            out.push(`\uE000${codeOnly[1]}\uE000`);

            continue;
        }

        if (line.trim() === '') {
            flushParagraph();
            closeBlock();

            continue;
        }

        const heading = line.match(/^(#{1,6})\s+(.*)$/);

        if (heading) {
            flushParagraph();
            closeBlock();
            const level = heading[1].length;
            out.push(`<h${level}>${inline(heading[2].trim())}</h${level}>`);

            continue;
        }

        if (/^\s*(-{3,}|\*{3,}|_{3,})\s*$/.test(line)) {
            flushParagraph();
            closeBlock();
            out.push('<hr>');

            continue;
        }

        // A blockquote line arrives escaped, so its marker is `&gt;`.
        const quote = line.match(/^&gt;\s?(.*)$/);

        if (quote) {
            flushParagraph();

            if (mode !== 'quote') {
                closeBlock();
                out.push('<blockquote>');
                mode = 'quote';
            }

            out.push(`<p>${inline(quote[1])}</p>`);

            continue;
        }

        const bullet = line.match(/^\s*[-*+]\s+(.*)$/);

        if (bullet) {
            flushParagraph();

            if (mode !== 'ul') {
                closeBlock();
                out.push('<ul>');
                mode = 'ul';
            }

            out.push(`<li>${inline(bullet[1])}</li>`);

            continue;
        }

        const numbered = line.match(/^\s*\d+[.)]\s+(.*)$/);

        if (numbered) {
            flushParagraph();

            if (mode !== 'ol') {
                closeBlock();
                out.push('<ol>');
                mode = 'ol';
            }

            out.push(`<li>${inline(numbered[1])}</li>`);

            continue;
        }

        closeBlock();
        paragraph.push(inline(line.trim()));
    }

    flushParagraph();
    closeBlock();

    return out.join('\n');
}

/**
 * The inline grammar, applied to a single line that is already escaped.
 *
 * Order matters: links before emphasis, so a URL's own `_` and `*` are inside an
 * attribute by the time the emphasis rules run; and code already lives in a
 * placeholder, so nothing here can reach inside it.
 */
function inline(line: string): string {
    let out = line;

    out = out.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, (_match, alt: string, url: string) => {
        const safe = safeUrl(url);

        return safe === null ? alt : `<a href="${safe}" target="_blank" rel="noopener noreferrer">${alt}</a>`;
    });

    out = out.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_match, label: string, url: string) => {
        const safe = safeUrl(url);

        return safe === null ? `${label} (${url})` : `<a href="${safe}" target="_blank" rel="noopener noreferrer">${label}</a>`;
    });

    // Bare URLs, not already inside an anchor's quotes. The lookbehind is why
    // this runs after the explicit links above.
    out = out.replace(/(^|[\s(])(https?:\/\/[^\s<>()"']+)/g, (_match, prefix: string, url: string) =>
        `${prefix}<a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>`,
    );

    out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    out = out.replace(/__([^_]+)__/g, '<strong>$1</strong>');
    out = out.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
    out = out.replace(/(^|[^_])_([^_\n]+)_/g, '$1<em>$2</em>');

    return out;
}

/**
 * The same answer, as something a screen reader should say.
 *
 * Markdown is punctuation. Read literally, `**Cartera**` becomes "asterisk
 * asterisk Cartera asterisk asterisk", a numbered source list becomes a stream
 * of digits, and a heading becomes a hash. This removes the punctuation the
 * renderer above turns into structure, keeping the words — so a reply that is
 * pleasant to read is also pleasant to hear.
 */
export function stripMarkdown(source: string): string {
    return source
        .replace(/\r\n?/g, '\n')
        .replace(/```[\s\S]*?```/g, ' ')
        .replace(/`([^`]*)`/g, '$1')
        .replace(/!\[([^\]]*)\]\([^)]*\)/g, '$1')
        .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
        .replace(/^\s{0,3}#{1,6}\s+/gm, '')
        .replace(/^\s*&gt;\s?/gm, '')
        .replace(/^\s*>\s?/gm, '')
        .replace(/^\s*[-*+]\s+/gm, '')
        .replace(/^\s*\d+[.)]\s+/gm, '')
        .replace(/^\s*([-*_])\1{2,}\s*$/gm, '')
        .replace(/(\*\*|__)(.*?)\1/g, '$2')
        .replace(/(\*|_)(.*?)\1/g, '$2')
        .replace(/[ \t]+/g, ' ')
        .replace(/\n{2,}/g, '. ')
        .replace(/\n/g, ' ')
        .trim();
}
