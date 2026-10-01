/**
 * Shapes returned by the Consultora DH JSON API.
 *
 * These mirror the API resources in app/Http/Resources and the array payloads
 * produced by the A01 controllers. Keeping them in one place means a change in
 * the API contract surfaces as a type error instead of a runtime surprise.
 */

export type UserStatus = 'active' | 'inactive';

export type ServiceStatus = 'operational' | 'unavailable';

/** Field name -> list of messages, as produced by Laravel's validator. */
export type FieldErrors = Record<string, string[]>;

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    status: UserStatus;
    status_label: string;
    initials: string;
    roles: string[];
    primary_role: string | null;
    permissions: string[];
    last_login_at: string | null;
    email_verified_at: string | null;
}

export interface ServiceCheck {
    status: ServiceStatus;
    label: string;
    detail: string | null;
}

export interface DashboardPayload {
    greeting: {
        name: string;
        first_name: string;
        date: string;
    };
    user: {
        email: string;
        primary_role: string | null;
        roles: string[];
        status: UserStatus;
        last_login_at: string | null;
    };
    application: {
        name: string;
        version: string;
        environment: string;
        timezone: string;
        locale: string;
    };
    services: {
        database: ServiceCheck;
        redis: ServiceCheck;
    };
}

export interface SettingsPayload {
    application: {
        name: string;
        version: string;
        environment: string;
        debug: boolean;
        url: string;
    };
    locale: {
        locale: string;
        fallback: string;
        timezone: string;
    };
    infrastructure: {
        database: string;
        cache: string;
        queue: string;
        sessions: string;
    };
    security: {
        session_cookie_secure: boolean;
        content_security_policy: boolean;
        debug_enabled: boolean;
    };
    access: {
        primary_role: string | null;
        roles: string[];
        permissions: string[];
    };
}

export interface HealthPayload {
    status: 'ok' | 'degraded';
    application: string;
    version: string;
    checks: {
        database: 'up' | 'down';
        redis: 'up' | 'down';
    };
}

/** Response of the mutation endpoints that only return a confirmation. */
export interface MessagePayload {
    message: string;
}

export interface LoginPayload {
    user: AuthUser;
}

export interface UpdateEmailPayload extends MessagePayload {
    user: AuthUser;
}

export interface UserPayload {
    user: AuthUser;
}
