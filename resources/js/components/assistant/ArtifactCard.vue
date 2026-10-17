<script setup lang="ts">
import { computed } from 'vue';

import type { AiArtifact } from '@/services/ai';

/**
 * A file the assistant produced, ready to download.
 *
 * ## Why it is a link and not a button with a fetch
 *
 * The download endpoint is authorised by session and answers a real `GET`, so an
 * anchor is both the simplest and the most correct control: the browser handles
 * the progress, the save dialog and the file name, and nothing has to be held in
 * memory or turned into a blob first. `download` asks for the name the server
 * gave; the endpoint sets its own `Content-Disposition` regardless, so a hostile
 * `filename` cannot talk the browser into saving into an unexpected place.
 */
const props = defineProps<{ artifact: AiArtifact }>();

const icon = computed(() => {
    switch (props.artifact.format.toLowerCase()) {
        case 'pdf':
            return 'bi-file-earmark-pdf';
        case 'docx':
        case 'doc':
            return 'bi-file-earmark-word';
        case 'xlsx':
        case 'xls':
            return 'bi-file-earmark-excel';
        case 'csv':
            return 'bi-file-earmark-spreadsheet';
        case 'md':
        case 'txt':
            return 'bi-file-earmark-text';
        default:
            return 'bi-file-earmark';
    }
});

const readableSize = computed(() => formatBytes(props.artifact.size_bytes));

/** Bytes in the unit a person reads, not a raw count. */
function formatBytes(bytes: number): string {
    if (!Number.isFinite(bytes) || bytes <= 0) {
        return 'Archivo';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    const rounded = unit === 0 ? value : Math.round(value * 10) / 10;

    return `${rounded} ${units[unit]}`;
}
</script>

<template>
    <a class="cdh-artifact" :href="artifact.download_url" :download="artifact.filename" data-test="artifact-card">
        <i class="bi" :class="icon" aria-hidden="true" />

        <span class="cdh-artifact__body">
            <span class="cdh-artifact__name">{{ artifact.filename }}</span>
            <span class="cdh-artifact__meta">{{ artifact.format.toUpperCase() }} · {{ readableSize }}</span>
        </span>

        <i class="bi bi-download cdh-artifact__action" aria-hidden="true" />
        <span class="visually-hidden">Descargar {{ artifact.filename }}</span>
    </a>
</template>

<style scoped>
.cdh-artifact {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.5rem 0.7rem;
    border: 1px solid var(--cdh-border, #d5d9e0);
    border-radius: 0.5rem;
    background: var(--cdh-surface, #fff);
    color: inherit;
    text-decoration: none;
    /* A long file name must not widen its container. */
    min-width: 0;
}

.cdh-artifact:hover {
    border-color: var(--cdh-primary, #2563eb);
}

.cdh-artifact:focus-visible {
    outline: 2px solid var(--cdh-primary, #2563eb);
    outline-offset: 2px;
}

.cdh-artifact > .bi:first-child {
    font-size: 1.3rem;
    color: var(--cdh-primary, #2563eb);
    flex: 0 0 auto;
}

.cdh-artifact__body {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1 1 auto;
}

.cdh-artifact__name {
    font-size: 0.85rem;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.cdh-artifact__meta {
    font-size: 0.72rem;
    color: var(--cdh-text-muted, #6b7280);
}

.cdh-artifact__action {
    flex: 0 0 auto;
    color: var(--cdh-text-muted, #6b7280);
}
</style>
