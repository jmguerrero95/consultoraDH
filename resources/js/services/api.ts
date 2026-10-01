import { request } from '@/services/http';

import type {
    AuthUser,
    DashboardPayload,
    HealthPayload,
    LoginPayload,
    MessagePayload,
    SettingsPayload,
    UpdateEmailPayload,
    UserPayload,
} from '@/types/api';

/**
 * Typed access to the Consultora DH JSON API.
 *
 * Pages and stores never build URLs themselves; they call one of these
 * functions, so the contract lives in a single module.
 */
export const api = {
    health(): Promise<HealthPayload> {
        return request<HealthPayload>('GET', '/api/health');
    },

    auth: {
        /** Resolve the current session. Rejects with 401 when there is none. */
        me(): Promise<UserPayload> {
            return request<UserPayload>('GET', '/api/auth/me');
        },

        login(email: string, password: string, remember: boolean): Promise<LoginPayload> {
            return request<LoginPayload>('POST', '/api/auth/login', {
                body: { email, password, remember },
            });
        },

        logout(): Promise<MessagePayload> {
            return request<MessagePayload>('POST', '/api/auth/logout');
        },

        forgotPassword(email: string): Promise<MessagePayload> {
            return request<MessagePayload>('POST', '/api/auth/forgot-password', {
                body: { email },
            });
        },

        resetPassword(payload: {
            token: string;
            email: string;
            password: string;
            password_confirmation: string;
        }): Promise<MessagePayload> {
            return request<MessagePayload>('POST', '/api/auth/reset-password', { body: payload });
        },
    },

    dashboard(): Promise<DashboardPayload> {
        return request<DashboardPayload>('GET', '/api/dashboard');
    },

    profile: {
        show(): Promise<UserPayload> {
            return request<UserPayload>('GET', '/api/profile');
        },

        update(payload: { name: string }): Promise<UserPayload> {
            return request<UserPayload>('PATCH', '/api/profile', { body: payload });
        },

        updateEmail(payload: { email: string; current_password: string }): Promise<UpdateEmailPayload> {
            return request<UpdateEmailPayload>('PUT', '/api/profile/email', { body: payload });
        },

        updatePassword(payload: {
            current_password: string;
            password: string;
            password_confirmation: string;
        }): Promise<MessagePayload> {
            return request<MessagePayload>('PUT', '/api/profile/password', { body: payload });
        },
    },

    settings(): Promise<SettingsPayload> {
        return request<SettingsPayload>('GET', '/api/settings');
    },
} as const;

/** Re-exported so components do not need to know where the user type lives. */
export type { AuthUser };
