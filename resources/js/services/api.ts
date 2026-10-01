import { request } from '@/services/http';

import type {
    Affiliation,
    Assignment,
    AuthUser,
    Client,
    ClientDetailPayload,
    ClientListPayload,
    Company,
    CompanyDetailPayload,
    CompanyListPayload,
    CompanySummary,
    DashboardPayload,
    EntityListPayload,
    HealthPayload,
    LoginPayload,
    MessagePayload,
    SettingsPayload,
    SocialSecurityEntity,
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

/* -------------------------------------------------------------------------
 | A02: the business domain
 |------------------------------------------------------------------------- */

/**
 * Query parameters shared by the paginated list endpoints.
 *
 * Declared with an index signature so it satisfies the transport's query type
 * directly, instead of every caller spreading it into a new object.
 */
export interface ListParams {
    search?: string;
    status?: string;
    per_page?: number;
    page?: number;
    [key: string]: string | number | boolean | null | undefined;
}

/**
 * The A02 surface.
 *
 * `clients.list` and `clients.show` are separate on purpose: the list must stay
 * narrow enough to render two hundred rows quickly, and the detail screen needs
 * fields the list never sends.
 */
export const businessApi = {
    clients: {
        list(params: ListParams & { company_id?: number | null } = {}): Promise<ClientListPayload> {
            return request<ClientListPayload>('GET', '/api/clients', { query: params });
        },

        show(id: number): Promise<ClientDetailPayload> {
            return request<ClientDetailPayload>('GET', `/api/clients/${id}`);
        },

        create(payload: Record<string, string | null>): Promise<{ message: string; client: Client }> {
            return request('POST', '/api/clients', { body: payload });
        },

        update(id: number, payload: Record<string, string | null>): Promise<{ message: string; client: Client }> {
            return request('PATCH', `/api/clients/${id}`, { body: payload });
        },

        /**
         * Activation and deactivation, as its own operation.
         *
         * `when` says what to do about open relationships: `block` to refuse, or
         * `close` to close them on a date. The server refuses the second when the
         * first is impossible, so the interface has to be explicit rather than
         * optimistic.
         */
        changeStatus(
            id: number,
            payload: { status: 'active' | 'inactive'; when: 'block' | 'close'; effective_date?: string | null },
        ): Promise<{ message: string; client: Client }> {
            return request('POST', `/api/clients/${id}/status`, { body: payload });
        },

        companyOptions(search?: string): Promise<{ companies: CompanySummary[] }> {
            return request('GET', '/api/company-options', { query: { search } });
        },
    },

    relationships: {
        link(
            clientId: number,
            payload: {
                company_id: number;
                started_on: string;
                job_title?: string | null;
                notes?: string | null;
                resolution: string;
                parallel_reason?: string | null;
            },
        ): Promise<{ message: string; assignment: Assignment }> {
            return request('POST', `/api/clients/${clientId}/companies`, { body: payload });
        },

        close(
            assignmentId: number,
            payload: { ended_on: string; reason?: string | null },
        ): Promise<{ message: string; assignment: Assignment }> {
            return request('POST', `/api/client-company-assignments/${assignmentId}/close`, { body: payload });
        },

        transfer(
            assignmentId: number,
            payload: {
                to_company_id: number;
                effective_on: string;
                job_title?: string | null;
                notes?: string | null;
            },
        ): Promise<{ message: string; closed_assignment: Assignment; assignment: Assignment }> {
            return request('POST', `/api/client-company-assignments/${assignmentId}/transfer`, { body: payload });
        },
    },

    affiliations: {
        create(
            clientId: number,
            payload: {
                social_security_entity_id: number;
                type: string;
                started_on?: string | null;
                arl_risk_class?: number | null;
                client_company_assignment_id?: number | null;
                notes?: string | null;
                replace_current?: boolean;
                effective_date?: string | null;
            },
        ): Promise<{ message: string; affiliation: Affiliation }> {
            return request('POST', `/api/clients/${clientId}/affiliations`, { body: payload });
        },

        close(
            affiliationId: number,
            payload: { ended_on: string; reason?: string | null },
        ): Promise<{ message: string; affiliation: Affiliation }> {
            return request('POST', `/api/client-affiliations/${affiliationId}/close`, { body: payload });
        },

        changeEntity(
            affiliationId: number,
            payload: {
                social_security_entity_id: number;
                type: string;
                effective_date: string;
                arl_risk_class?: number | null;
                notes?: string | null;
            },
        ): Promise<{ message: string; closed_affiliation: Affiliation; affiliation: Affiliation }> {
            return request('POST', `/api/client-affiliations/${affiliationId}/change`, { body: payload });
        },
    },

    companies: {
        list(params: ListParams = {}): Promise<CompanyListPayload> {
            return request<CompanyListPayload>('GET', '/api/companies', { query: params });
        },

        show(id: number): Promise<CompanyDetailPayload> {
            return request<CompanyDetailPayload>('GET', `/api/companies/${id}`);
        },

        create(payload: Record<string, string | null>): Promise<{ message: string; company: Company }> {
            return request('POST', '/api/companies', { body: payload });
        },

        update(id: number, payload: Record<string, string | null>): Promise<{ message: string; company: Company }> {
            return request('PATCH', `/api/companies/${id}`, { body: payload });
        },

        changeStatus(
            id: number,
            payload: { status: 'active' | 'inactive'; reason?: string | null },
        ): Promise<{ message: string; company: Company }> {
            return request('POST', `/api/companies/${id}/status`, { body: payload });
        },
    },

    entities: {
        list(params: ListParams & { type?: string | null } = {}): Promise<EntityListPayload> {
            return request<EntityListPayload>('GET', '/api/social-security-entities', { query: params });
        },

        create(payload: Record<string, string | null>): Promise<{
            message: string;
            entity: SocialSecurityEntity;
        }> {
            return request('POST', '/api/social-security-entities', { body: payload });
        },

        update(id: number, payload: Record<string, string | null>): Promise<{
            message: string;
            entity: SocialSecurityEntity;
        }> {
            return request('PATCH', `/api/social-security-entities/${id}`, { body: payload });
        },

        deactivate(id: number): Promise<{ message: string; entity: SocialSecurityEntity }> {
            return request('POST', `/api/social-security-entities/${id}/deactivate`);
        },
    },
} as const;
