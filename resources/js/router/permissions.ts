import type { RouteMeta } from 'vue-router';

/**
 * Whether an account may be shown a route or a navigation entry.
 *
 * ## Why this is its own module
 *
 * The guard and the sidebar both have to answer the same question. When they answered it
 * separately — the guard reading `meta.permission` and the sidebar reading it too — the two
 * could disagree, and the disagreement showed up as a link in the menu that led to a red
 * "forbidden" screen. One function, imported by both, means a route cannot be visible in one
 * place and denied in the other.
 *
 * ## Three forms, one per route
 *
 *   `permissionsAll`  every permission is required
 *   `permissionsAny`  at least one is required
 *   `permission`      the single required permission
 *
 * They are read in that order and a route declares at most one, so the answer is
 * unambiguous. A route with none of them is open to any authenticated account, which is
 * what the dashboard and the shell routes are.
 *
 * ## Empty lists
 *
 * An empty `permissionsAny` means "no restriction" rather than "deny". A list of nothing is
 * a configuration mistake, and refusing every account because of one would be a very
 * confusing mistake to discover; the list that was meant to be written is visible in the
 * route file either way.
 *
 * An empty `permissionsAll` means the same thing for the same reason.
 */
export function puedeEntrar(meta: RouteMeta, can: (permission: string) => boolean): boolean {
    const todos = meta.permissionsAll;

    if (todos !== undefined && todos.length > 0) {
        return todos.every((permission) => can(permission));
    }

    const alguno = meta.permissionsAny;

    if (alguno !== undefined && alguno.length > 0) {
        return alguno.some((permission) => can(permission));
    }

    if (meta.permission !== undefined) {
        return can(meta.permission);
    }

    return true;
}