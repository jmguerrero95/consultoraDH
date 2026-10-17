<script setup lang="ts">
import { computed } from 'vue';

import { renderMarkdown } from '@/utils/markdown';

/**
 * An assistant reply, rendered from Markdown.
 *
 * The rendering itself lives in `@/utils/markdown`, which escapes the text
 * before it decorates it, so binding the result with `v-html` here cannot
 * introduce a tag the renderer did not write. This component exists so both the
 * floating panel and the full screen share one renderer rather than each
 * deciding for itself what an answer is allowed to contain.
 */
const props = defineProps<{ content: string }>();

const html = computed(() => renderMarkdown(props.content));
</script>

<template>
    <!-- eslint-disable-next-line vue/no-v-html -- content is HTML-escaped by renderMarkdown before any tag is added -->
    <div class="cdh-markdown" v-html="html" />
</template>

<style scoped>
.cdh-markdown {
    overflow-wrap: anywhere;
}

.cdh-markdown :deep(p) {
    margin: 0 0 0.5em;
}

.cdh-markdown :deep(p:last-child) {
    margin-bottom: 0;
}

.cdh-markdown :deep(h1),
.cdh-markdown :deep(h2),
.cdh-markdown :deep(h3),
.cdh-markdown :deep(h4),
.cdh-markdown :deep(h5),
.cdh-markdown :deep(h6) {
    margin: 0.75em 0 0.35em;
    font-size: 1em;
    font-weight: 700;
    line-height: 1.3;
}

.cdh-markdown :deep(h1:first-child),
.cdh-markdown :deep(h2:first-child),
.cdh-markdown :deep(h3:first-child) {
    margin-top: 0;
}

.cdh-markdown :deep(ul),
.cdh-markdown :deep(ol) {
    margin: 0.35em 0 0.5em;
    padding-left: 1.25em;
}

.cdh-markdown :deep(li) {
    margin: 0.15em 0;
}

.cdh-markdown :deep(a) {
    color: inherit;
    text-decoration: underline;
    text-underline-offset: 2px;
    /* A pasted link is a long token: without this a single URL widens the whole
       transcript and the reply scrolls sideways. */
    overflow-wrap: anywhere;
}

.cdh-markdown :deep(code) {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 0.875em;
    background: rgba(0, 0, 0, 0.06);
    border-radius: 0.25em;
    padding: 0.05em 0.35em;
}

.cdh-markdown :deep(pre) {
    margin: 0.5em 0;
    padding: 0.6em 0.75em;
    background: rgba(0, 0, 0, 0.06);
    border-radius: 0.375em;
    overflow-x: auto;
}

.cdh-markdown :deep(pre code) {
    background: transparent;
    padding: 0;
}

.cdh-markdown :deep(blockquote) {
    margin: 0.5em 0;
    padding-left: 0.75em;
    border-left: 3px solid currentColor;
    opacity: 0.85;
}

.cdh-markdown :deep(hr) {
    border: 0;
    border-top: 1px solid rgba(0, 0, 0, 0.15);
    margin: 0.75em 0;
}
</style>
