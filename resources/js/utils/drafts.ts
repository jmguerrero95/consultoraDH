/**
 * The unsent text a person has typed, kept across a reload.
 *
 * ## Why `sessionStorage` and not `localStorage`
 *
 * A draft is scoped to a browser tab: it survives F5, which is the defect it
 * fixes, and it disappears when the tab closes, which is the safe default on a
 * shared machine. It is never sent anywhere and never leaves the browser.
 *
 * ## Scoped to the person and the thread
 *
 * The key carries the authenticated user id *and* the conversation id, so a
 * different account signing in on the same browser cannot see the previous
 * person's text, and a draft typed for one conversation does not appear in
 * another. `clearDrafts()` removes every draft on logout, so nothing survives
 * the session ending.
 *
 * ## Not a cache of anything else
 *
 * Only the composer's own text is stored: never a message, never a document,
 * never a token.
 */

const PREFIX = 'cdh.asistente.draft.';

function storage(): Storage | null {
    try {
        if (typeof window === 'undefined' || !window.sessionStorage) {
            return null;
        }

        return window.sessionStorage;
    } catch {
        // A browser with storage disabled (private mode with strict settings)
        // must degrade to "no draft persistence", never to an exception.
        return null;
    }
}

/** The key for one person's draft in one thread. `null` conversation = a new one. */
export function draftKey(userId: number | null, conversationId: number | null): string {
    return `${PREFIX}${userId ?? 'anon'}.${conversationId ?? 'new'}`;
}

export function readDraft(userId: number | null, conversationId: number | null): string {
    const store = storage();

    if (store === null) {
        return '';
    }

    return store.getItem(draftKey(userId, conversationId)) ?? '';
}

export function writeDraft(userId: number | null, conversationId: number | null, value: string): void {
    const store = storage();

    if (store === null) {
        return;
    }

    const key = draftKey(userId, conversationId);

    if (value === '') {
        store.removeItem(key);

        return;
    }

    store.setItem(key, value);
}

/** Remove every draft this application ever stored. Called on logout. */
export function clearDrafts(): void {
    const store = storage();

    if (store === null) {
        return;
    }

    const keys: string[] = [];

    for (let index = 0; index < store.length; index += 1) {
        const key = store.key(index);

        if (key !== null && key.startsWith(PREFIX)) {
            keys.push(key);
        }
    }

    keys.forEach((key) => store.removeItem(key));
}
