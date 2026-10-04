import { request } from '@/services/http';

/**
 * A month as the server stores it for effective-dated configuration.
 *
 * A cutoff and a rate are effective *from* a date, so they are stored as the first
 * day of that month and sent that way. A period is named rather than dated — an
 * operator says "October", not "1 October" — so it is sent as `YYYY-MM`. The two
 * conversions live here so no page has to know about either of them.
 */
function comoMesDeVigencia(mes: string): string {
    return /-\d{2}$/.test(mes) ? `${mes}-01` : mes;
}

import type {
    AdjustmentSummary,
    Affiliation,
    Assignment,
    AutoAllocationPlan,
    BillingVocabularyPayload,
    ClientAccountPayload,
    AuthUser,
    Client,
    ClientDetailPayload,
    ClientListPayload,
    Company,
    CompanyDetailPayload,
    CompanyListPayload,
    CompanySummary,
    CutoffRuleListPayload,
    CutoffRuleSummary,
    DashboardPayload,
    EntityListPayload,
    HealthPayload,
    LoginPayload,
    GenerationPreviewPayload,
    GenerationResultPayload,
    MessagePayload,
    ObligationSummary,
    Pagination,
    PaymentListPayload,
    PaymentSummary,
    PeriodListPayload,
    PeriodSummary,
    RateHistoryPayload,
    RateListPayload,
    RateSummary,
    ReceivablesListPayload,
    SettingsPayload,
    SocialSecurityEntity,
    UpdateEmailPayload,
    UserPayload,
    AdjustmentListPayload,
    AdjustmentVocabularyPayload,
    AllocatableResponse,
    PaymentVocabularyPayload,
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

    /**
     * The A03 surface: periods, obligations, payments and receivables.
     *
     * Every figure arrives as a whole number of pesos. Nothing here formats money,
     * and nothing here accepts a formatted string: the interface converts for
     * display, the server validates as an integer, and no step in between invents a
     * value.
     */
    periods: {
        list(params: ListParams = {}): Promise<PeriodListPayload> {
            return request<PeriodListPayload>('GET', '/api/periods', { query: params });
        },

        /**
         * The open period, for a caller that only needs that one value.
         */
        openPeriods(): Promise<{ current: PeriodSummary | null; open_periods: PeriodSummary[] }> {
            return request('GET', '/api/periods/current');
        },

        current(): Promise<{ current: PeriodSummary | null; open_periods: PeriodSummary[] }> {
            return request('GET', '/api/periods/current');
        },

        show(id: number): Promise<{ period: PeriodSummary }> {
            return request('GET', `/api/periods/${id}`);
        },

        open(month: string): Promise<{ message: string; period: PeriodSummary }> {
            return request('POST', '/api/periods', { body: { period_month: month } });
        },

        close(id: number, reason?: string | null): Promise<{ message: string; period: PeriodSummary }> {
            return request('POST', `/api/periods/${id}/close`, {
                body: { reason: reason ?? null, confirm: true },
            });
        },

        reopen(id: number, reason: string): Promise<{ message: string; period: PeriodSummary }> {
            return request('POST', `/api/periods/${id}/reopen`, {
                body: { reason, confirm: true },
            });
        },

        obligations(
            id: number,
            params: ListParams & { client_id?: number | null; company_id?: number | null; settlement_state?: string | null } = {},
        ): Promise<{ items: ObligationSummary[]; pagination: Pagination }> {
            return request('GET', `/api/periods/${id}/obligations`, { query: params });
        },

        /**
         * What generation would do, before it does it.
         *
         * Read only, and the reason the confirmation dialog can list the blockers
         * rather than only reporting that something went wrong.
         */
        /**
         * What generation would do.
         *
         * Takes no flag: generation is unconditionally missing-only, so a second plan would
         * be a promise the action does not keep.
         */
        previewObligations(id: number): Promise<GenerationPreviewPayload> {
            return request('POST', `/api/periods/${id}/obligations/preview`);
        },

        generateObligations(id: number): Promise<GenerationResultPayload> {
            return request('POST', `/api/periods/${id}/obligations/generate`);
        },
    },

    obligations: {
        adjust(
            id: number,
            payload: { type: string; delta_cop: number; reason: string },
        ): Promise<{ message: string; adjustment: AdjustmentSummary; obligation: ObligationSummary }> {
            return request('POST', `/api/obligations/${id}/adjustments`, { body: payload });
        },

        /**
         * The adjustment ledger of one obligation.
         *
         * `items`, matching the backend. It used to be typed and read as `adjustments`, so
         * the history modal received `undefined` from a request that had **succeeded** and
         * reported "no fue posible cargar los ajustes".
         */
        adjustments(id: number): Promise<AdjustmentListPayload> {
            return request('GET', `/api/obligations/${id}/adjustments`);
        },

        /**
         * The adjustment types and the direction each one accepts.
         *
         * From the same enum the domain enforces, so the dropdown cannot offer less than the
         * API accepts — which is how `credit` went missing from the screen for a release.
         */
        adjustmentVocabulary(): Promise<AdjustmentVocabularyPayload> {
            return request('GET', '/api/obligation-adjustments/vocabulary');
        },

        reverseAdjustment(
            adjustmentId: number,
            reason: string,
        ): Promise<{ message: string; adjustment: AdjustmentSummary }> {
            return request('POST', `/api/obligation-adjustments/${adjustmentId}/reverse`, {
                body: { reason, confirm: true },
            });
        },
    },

    payments: {
        list(
            params: ListParams & {
                client_id?: number | null;
                state?: string | null;
                method?: string | null;
                date_from?: string | null;
                date_to?: string | null;
                requires_reconciliation?: boolean;
            } = {},
        ): Promise<PaymentListPayload> {
            return request<PaymentListPayload>('GET', '/api/payments', { query: params });
        },

        show(id: number): Promise<{ payment: PaymentSummary }> {
            return request('GET', `/api/payments/${id}`);
        },

        register(payload: {
            client_id: number;
            amount_cop: number;
            received_on: string;
            method: string;
            reference?: string | null;
            notes?: string | null;
            confirm_duplicate?: boolean;
        }): Promise<{ message: string; payment: PaymentSummary }> {
            return request('POST', '/api/payments', { body: payload });
        },

        allocate(
            paymentId: number,
            payload: { obligation_id: number; amount_cop: number },
        ): Promise<{ message: string; allocation: unknown; payment: PaymentSummary }> {
            return request('POST', `/api/payments/${paymentId}/allocations`, { body: payload });
        },

        /**
         * The debts this client could be applied to.
         *
         * §42: manual allocation loaded the client statement through `receivables.view`, so
         * `payments.allocate` depended on a permission a collections role has no reason to
         * hold. This is the payments domain's own answer and it publishes only what choosing
         * a debt needs.
         */
        allocatable(clientId: number): Promise<AllocatableResponse> {
            return request('GET', `/api/payments/clients/${clientId}/allocatable`);
        },

        /**
         * The payment vocabulary.
         *
         * §42, second half: the payments page populated its method select from
         * `/api/receivables/vocabulary`, so a role with payments permissions and no
         * receivables permission got an empty dropdown and could not record how money
         * arrived.
         */
        vocabulary(): Promise<PaymentVocabularyPayload> {
            return request('GET', '/api/payments/vocabulary');
        },

        previewAutoAllocation(paymentId: number): Promise<{ plan: AutoAllocationPlan }> {
            return request('POST', `/api/payments/${paymentId}/auto-allocate/preview`);
        },

        autoAllocate(paymentId: number): Promise<{
            message: string;
            plan: AutoAllocationPlan;
            payment: PaymentSummary;
        }> {
            return request('POST', `/api/payments/${paymentId}/auto-allocate`);
        },

        /**
         * Undo one allocation, keeping it visible in the history.
         *
         * §17. The endpoint existed while the interface had no way to reach it, so correcting
         * a misapplied payment meant writing a request by hand — and a correction an
         * operator cannot perform tends to get worked around in the ledger instead of in it.
         *
         * `reason` is the record of why, and `confirm` is what the server demands for an
         * action that moves money back: the allocation is not deleted, it is marked undone,
         * so somebody reading the history later has to be able to tell the difference.
         */
        reverseAllocation(
            allocationId: number,
            reason: string,
        ): Promise<{
            message: string;
            allocation: { id: number; reversed_at: string | null; reversal_reason: string | null };
            payment: PaymentSummary;
        }> {
            return request('POST', `/api/payment-allocations/${allocationId}/reverse`, {
                body: { reason, confirm: true },
            });
        },

        /**
         * Invalidate a payment.
         *
         * `confirm` is sent because the server demands it: a void takes money out of
         * every balance the payment touched, and a request that only carried a reason
         * would be indistinguishable from a mis-click on a form. The dialog is the
         * confirmation; this is the server being sure one happened.
         */
        void(paymentId: number, reason: string): Promise<{ message: string; payment: PaymentSummary }> {
            return request('POST', `/api/payments/${paymentId}/void`, {
                body: { reason, confirm: true },
            });
        },
    },

    receivables: {
        list(
            params: ListParams & {
                as_of?: string | null;
                client_id?: number | null;
                company_id?: number | null;
                settlement_state?: string | null;
                aging_bucket?: string | null;
                traffic_light?: string | null;
                overdue?: boolean;
                outstanding_only?: boolean;
                minimum_balance?: number | null;
                maximum_balance?: number | null;
            } = {},
        ): Promise<ReceivablesListPayload> {
            return request<ReceivablesListPayload>('GET', '/api/receivables', { query: params });
        },

        clientAccount(id: number, asOf?: string | null): Promise<ClientAccountPayload> {
            return request('GET', `/api/clients/${id}/account`, { query: { as_of: asOf } });
        },

        vocabulary(): Promise<BillingVocabularyPayload> {
            return request<BillingVocabularyPayload>('GET', '/api/receivables/vocabulary');
        },
    },

    billing: {
        cutoffRules(params: ListParams & { scope?: string | null } = {}): Promise<CutoffRuleListPayload> {
            return request('GET', '/api/cutoff-rules', { query: params });
        },

        storeCutoffRule(payload: {
            scope: string;
            client_id?: number | null;
            company_id?: number | null;
            effective_month: string;
            cutoff_day: number;
            month_offset: number;
            notes?: string | null;
        }): Promise<{ message: string; rule: CutoffRuleSummary }> {
            return request('POST', '/api/cutoff-rules', {
                body: { ...payload, effective_month: comoMesDeVigencia(payload.effective_month) },
            });
        },

        updateCutoffRule(
            id: number,
            payload: { cutoff_day?: number; month_offset?: number; notes?: string | null; effective_month?: string },
        ): Promise<{ message: string; rule: CutoffRuleSummary }> {
            return request('PATCH', `/api/cutoff-rules/${id}`, {
                body:
                    payload.effective_month === undefined
                        ? payload
                        : { ...payload, effective_month: comoMesDeVigencia(payload.effective_month) },
            });
        },

        rates(params: ListParams & { client_id?: number | null; company_id?: number | null } = {}): Promise<RateListPayload> {
            return request('GET', '/api/rates', { query: params });
        },

        rateHistory(clientId: number, companyId?: number | null): Promise<RateHistoryPayload> {
            return request('GET', `/api/clients/${clientId}/rates`, { query: { company_id: companyId } });
        },

        storeRate(payload: {
            client_id: number;
            company_id: number;
            effective_month: string;
            amount_cop: number;
            notes?: string | null;
        }): Promise<{ message: string; rate: RateSummary }> {
            return request('POST', '/api/rates', { body: payload });
        },

        updateRate(
            id: number,
            payload: { amount_cop?: number; notes?: string | null },
        ): Promise<{ message: string; rate: RateSummary }> {
            return request('PATCH', `/api/rates/${id}`, { body: payload });
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
