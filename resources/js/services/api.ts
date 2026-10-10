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
    ImportDetailPayload,
    ImportIssuesPayload,
    ImportListPayload,
    ImportPlanPayload,
    ImportRetirementPolicy,
    ImportRowsPayload,
    IssueResolutionDecision,
    LegacyImport,
    LegacyImportDetail,
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
interface ApiClient {
    health(): Promise<HealthPayload>;

    get<T>(url: string, options?: { query?: Record<string, any>; headers?: Record<string, string> }): Promise<{ data: T }>;
    post<T>(url: string, options?: { body?: any; headers?: Record<string, string> }): Promise<{ data: T }>;

    auth: {
        me(): Promise<UserPayload>;
        login(email: string, password: string, remember: boolean): Promise<LoginPayload>;
        logout(): Promise<MessagePayload>;
        forgotPassword(email: string): Promise<MessagePayload>;
        resetPassword(payload: { token: string; email: string; password: string; password_confirmation: string }): Promise<MessagePayload>;
    };

    dashboard(): Promise<DashboardPayload>;

    profile: {
        show(): Promise<UserPayload>;
        update(payload: { name: string }): Promise<UserPayload>;
        updateEmail(payload: { email: string; current_password: string }): Promise<UpdateEmailPayload>;
        updatePassword(payload: { current_password: string; password: string; password_confirmation: string }): Promise<MessagePayload>;
    };

    settings(): Promise<SettingsPayload>;

    businessApi: {
        clients: {
            list(params: ListParams & { company_id?: number | null }): Promise<ClientListPayload>;
            show(id: number): Promise<ClientDetailPayload>;
            create(payload: Record<string, string | null>): Promise<{ message: string; client: Client }>;
            update(id: number, payload: Record<string, string | null>): Promise<{ message: string; client: Client }>;
            changeStatus(id: number, payload: { status: 'active' | 'inactive'; when: 'block' | 'close'; effective_date?: string | null }): Promise<{ message: string; client: Client }>;
            companyOptions(search?: string): Promise<{ companies: CompanySummary[] }>;
        };
        relationships: {
            link(clientId: number, payload: { company_id: number; started_on: string; job_title?: string | null; notes?: string | null; resolution: string; parallel_reason?: string | null }): Promise<{ message: string; assignment: Assignment }>;
            close(assignmentId: number, payload: { ended_on: string; reason?: string | null }): Promise<{ message: string; assignment: Assignment }>;
            transfer(assignmentId: number, payload: { to_company_id: number; effective_on: string; job_title?: string | null; notes?: string | null }): Promise<{ message: string; closed_assignment: Assignment; assignment: Assignment }>;
        };
        affiliations: {
            create(clientId: number, payload: { social_security_entity_id: number; type: string; started_on?: string | null; arl_risk_class?: number | null; client_company_assignment_id?: number | null; notes?: string | null; replace_current?: boolean; effective_date?: string | null }): Promise<{ message: string; affiliation: Affiliation }>;
            close(affiliationId: number, payload: { ended_on: string; reason?: string | null }): Promise<{ message: string; affiliation: Affiliation }>;
            changeEntity(affiliationId: number, payload: { social_security_entity_id: number; type: string; effective_date: string; arl_risk_class?: number | null; notes?: string | null }): Promise<{ message: string; closed_affiliation: Affiliation; affiliation: Affiliation }>;
        };
        companies: {
            list(params?: ListParams): Promise<CompanyListPayload>;
            show(id: number): Promise<CompanyDetailPayload>;
            create(payload: Record<string, string | null>): Promise<{ message: string; company: Company }>;
            update(id: number, payload: Record<string, string | null>): Promise<{ message: string; company: Company }>;
            changeStatus(id: number, payload: { status: 'active' | 'inactive'; reason?: string | null }): Promise<{ message: string; company: Company }>;
        };
        periods: {
            list(params?: ListParams): Promise<PeriodListPayload>;
            openPeriods(): Promise<{ current: PeriodSummary | null; open_periods: PeriodSummary[] }>;
            current(): Promise<{ current: PeriodSummary | null; open_periods: PeriodSummary[] }>;
            show(id: number): Promise<{ period: PeriodSummary }>;
            open(month: string): Promise<{ message: string; period: PeriodSummary }>;
            close(id: number, reason?: string | null): Promise<{ message: string; period: PeriodSummary }>;
            reopen(id: number, reason: string): Promise<{ message: string; period: PeriodSummary }>;
            obligations(id: number, params: ListParams & { client_id?: number | null; company_id?: number | null; settlement_state?: string | null }): Promise<{ items: ObligationSummary[]; pagination: Pagination }>;
            previewObligations(id: number): Promise<GenerationPreviewPayload>;
            generateObligations(id: number): Promise<GenerationResultPayload>;
        };
        obligations: {
            adjust(id: number, payload: { type: string; delta_cop: number; reason: string }): Promise<{ message: string; adjustment: AdjustmentSummary; obligation: ObligationSummary }>;
            adjustments(id: number): Promise<AdjustmentListPayload>;
            adjustmentVocabulary(): Promise<AdjustmentVocabularyPayload>;
            reverseAdjustment(adjustmentId: number, reason: string): Promise<{ message: string; adjustment: AdjustmentSummary }>;
        };
        payments: {
            list(params: ListParams & { client_id?: number | null; state?: string | null; method?: string | null; date_from?: string | null; date_to?: string | null; requires_reconciliation?: boolean }): Promise<PaymentListPayload>;
            show(id: number): Promise<{ payment: PaymentSummary }>;
            register(payload: { client_id: number; amount_cop: number; received_on: string; method: string; reference?: string | null; notes?: string | null; confirm_duplicate?: boolean }): Promise<{ message: string; payment: PaymentSummary }>;
            allocate(paymentId: number, payload: { obligation_id: number; amount_cop: number }): Promise<{ message: string; allocation: unknown; payment: PaymentSummary }>;
            allocatable(clientId: number): Promise<AllocatableResponse>;
            vocabulary(): Promise<PaymentVocabularyPayload>;
            previewAutoAllocation(paymentId: number): Promise<{ plan: AutoAllocationPlan }>;
            autoAllocate(paymentId: number): Promise<{ message: string; plan: AutoAllocationPlan; payment: PaymentSummary }>;
            reverseAllocation(allocationId: number, reason: string): Promise<{ message: string; allocation: { id: number; reversed_at: string | null; reversal_reason: string | null }; payment: PaymentSummary }>;
            void(paymentId: number, reason: string): Promise<{ message: string; payment: PaymentSummary }>;
        };
        receivables: {
            list(params: ListParams & { as_of?: string | null; client_id?: number | null; company_id?: number | null; settlement_state?: string | null; aging_bucket?: string | null; traffic_light?: string | null; overdue?: boolean; outstanding_only?: boolean; minimum_balance?: number | null; maximum_balance?: number | null }): Promise<ReceivablesListPayload>;
            clientAccount(id: number, asOf?: string | null): Promise<ClientAccountPayload>;
            vocabulary(): Promise<BillingVocabularyPayload>;
        };
        billing: {
            cutoffRules(params: ListParams & { scope?: string | null }): Promise<CutoffRuleListPayload>;
            storeCutoffRule(payload: { scope: string; client_id?: number | null; company_id?: number | null; effective_month: string; cutoff_day: number; month_offset: number; notes?: string | null }): Promise<{ message: string; rule: CutoffRuleSummary }>;
            updateCutoffRule(id: number, payload: { cutoff_day?: number; month_offset?: number; notes?: string | null; effective_month?: string }): Promise<{ message: string; rule: CutoffRuleSummary }>;
            rates(params: ListParams & { client_id?: number | null; company_id?: number | null }): Promise<RateListPayload>;
            rateHistory(clientId: number, companyId?: number | null): Promise<RateHistoryPayload>;
            storeRate(payload: { client_id: number; company_id: number; effective_month: string; amount_cop: number; notes?: string | null }): Promise<{ message: string; rate: RateSummary }>;
            updateRate(id: number, payload: { amount_cop?: number; notes?: string | null }): Promise<{ message: string; rate: RateSummary }>;
        };
        entities: {
            list(params: ListParams & { type?: string | null }): Promise<EntityListPayload>;
            create(payload: Record<string, string | null>): Promise<{ message: string; entity: SocialSecurityEntity }>;
            update(id: number, payload: Record<string, string | null>): Promise<{ message: string; entity: SocialSecurityEntity }>;
            deactivate(id: number): Promise<{ message: string; entity: SocialSecurityEntity }>;
        };
        imports: {
            list(params: ListParams & { status?: string | null }): Promise<ImportListPayload>;
            show(id: number): Promise<ImportDetailPayload>;
            upload(file: File): Promise<{ message: string; data: LegacyImport; previous_import_id?: number }>;
            rows(id: number, params: ListParams & { parse_state?: string | null; company_tax_id?: string | null; sheet?: string | null }): Promise<ImportRowsPayload>;
            issues(id: number, params: ListParams & { code?: string | null; severity?: string | null; blocking?: boolean; unresolved?: boolean }): Promise<ImportIssuesPayload>;
            plan(id: number): Promise<ImportPlanPayload>;
            setRetirementPolicy(id: number, policy: ImportRetirementPolicy): Promise<{ message: string }>;
            resolveIssue(id: number, issueId: number, resolution: { decision: IssueResolutionDecision; value?: Record<string, unknown> | null; note?: string | null }): Promise<{
                message: string;
                data: {
                    issue: { id: number; fingerprint: string; blocking: boolean; resolved_at: string | null; resolution: Record<string, unknown>; summary: string };
                    reusable_mapping: { type: string; source_key: string; social_security_entity_id: number } | null;
                };
            }>;
            bulkResolve(id: number, issueIds: number[], resolution: { decision: IssueResolutionDecision; value?: Record<string, unknown> | null; note?: string | null }): Promise<{
                message: string;
                resolved: number;
                resolved_ids: number[];
                refused: { id: number; code: string | null; message: string; code_reason: string }[];
            }>;
            rebuildPlan(id: number): Promise<{ message: string }>;
            apply(id: number, identity: { plan_revision: number; plan_digest: string }): Promise<{ message: string; data: LegacyImportDetail }>;
            cancel(id: number): Promise<{ message: string }>;
        };
    };

    support: {
        queues(): Promise<{ data: { id: number; name: string }[] }>;
        inbox(params: Record<string, any>): Promise<{ data: any[]; current_page: number; last_page: number; total: number; per_page: number }>;
        conversation(id: number, params: Record<string, any>): Promise<{ data: any }>;
        createConversation(payload: Record<string, any>): Promise<{ data: any }>;
        sendMessage(conversationId: number, payload: { body_text: string; attachment_ids?: number[] }): Promise<{ data: any }>;
        sendNote(conversationId: number, payload: { body_text: string }): Promise<{ data: any }>;
        uploadAttachment(conversationId: number, file: File): Promise<{ data: any }>;
        sendMessageWithAttachment(conversationId: number, payload: { body_text: string; attachment_ids: number[] }): Promise<{ data: any }>;
        changePriority(conversationId: number, priority: string): Promise<{ data: any }>;
        changeQueue(conversationId: number, payload: { queue_id: number; assignee_user_id?: number | null }): Promise<{ data: any }>;
        resolveConversation(conversationId: number): Promise<{ data: any }>;
        closeConversation(conversationId: number): Promise<{ data: any }>;
        reopenConversation(conversationId: number): Promise<{ data: any }>;
        assignConversation(conversationId: number, userId: number | null): Promise<{ data: any }>;
        loadMoreMessages(conversationId: number, beforeId: number, perPage: number): Promise<{ data: { messages: any[] } }>;
    };

    /**
     * Web Push. The browser holds the subscription; the server only ever stores
     * it encrypted, keyed by the hash of the endpoint.
     */
    push: {
        vapidPublicKey(): Promise<{ public_key: string }>;
        storeSubscription(payload: {
            endpoint: string;
            keys: { p256dh: string; auth: string };
        }): Promise<{ id: number }>;
        destroySubscription(id: number): Promise<{ message: string }>;
    };
}

export const api: ApiClient = {
    health(): Promise<HealthPayload> {
        return request<HealthPayload>('GET', '/api/health');
    },

    get<T>(url: string, options?: { query?: Record<string, any>; headers?: Record<string, string> }): Promise<{ data: T }> {
        return request<{ data: T }>('GET', url, options);
    },

    post<T>(url: string, options?: { body?: any; headers?: Record<string, string> }): Promise<{ data: T }> {
        return request<{ data: T }>('POST', url, options);
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

    businessApi: {
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

        periods: {
            list(params: ListParams = {}): Promise<PeriodListPayload> {
                return request<PeriodListPayload>('GET', '/api/periods', { query: params });
            },

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

            adjustments(id: number): Promise<AdjustmentListPayload> {
                return request('GET', `/api/obligations/${id}/adjustments`);
            },

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

            allocatable(clientId: number): Promise<AllocatableResponse> {
                return request('GET', `/api/payments/clients/${clientId}/allocatable`);
            },

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

        imports: {
            list(params: ListParams & { status?: string | null } = {}): Promise<ImportListPayload> {
                return request<ImportListPayload>('GET', '/api/imports', { query: params });
            },

            show(id: number): Promise<ImportDetailPayload> {
                return request<ImportDetailPayload>('GET', `/api/imports/${id}`);
            },

            upload(file: File): Promise<{ message: string; data: LegacyImport; previous_import_id?: number }> {
                const body = new FormData();
                body.append('file', file);

                return request('POST', '/api/imports', { body });
            },

            rows(
                id: number,
                params: ListParams & { parse_state?: string | null; company_tax_id?: string | null; sheet?: string | null } = {},
            ): Promise<ImportRowsPayload> {
                return request<ImportRowsPayload>('GET', `/api/imports/${id}/rows`, { query: params });
            },

            issues(
                id: number,
                params: ListParams & { code?: string | null; severity?: string | null; blocking?: boolean; unresolved?: boolean } = {},
            ): Promise<ImportIssuesPayload> {
                return request<ImportIssuesPayload>('GET', `/api/imports/${id}/issues`, { query: params });
            },

            plan(id: number): Promise<ImportPlanPayload> {
                return request<ImportPlanPayload>('GET', `/api/imports/${id}/plan`);
            },

            setRetirementPolicy(id: number, policy: ImportRetirementPolicy): Promise<{ message: string }> {
                return request('PUT', `/api/imports/${id}/interpretation-policy`, {
                    body: { retirement_policy: policy },
                });
            },

            resolveIssue(
                id: number,
                issueId: number,
                resolution: { decision: IssueResolutionDecision; value?: Record<string, unknown> | null; note?: string | null },
            ): Promise<{
                message: string;
                data: {
                    issue: { id: number; fingerprint: string; blocking: boolean; resolved_at: string | null; resolution: Record<string, unknown>; summary: string };
                    reusable_mapping: { type: string; source_key: string; social_security_entity_id: number } | null;
                };
            }> {
                return request('POST', `/api/imports/${id}/issues/${issueId}/resolve`, { body: { resolution } });
            },

            bulkResolve(
                id: number,
                issueIds: number[],
                resolution: { decision: IssueResolutionDecision; value?: Record<string, unknown> | null; note?: string | null },
            ): Promise<{
                message: string;
                resolved: number;
                resolved_ids: number[];
                refused: { id: number; code: string | null; message: string; code_reason: string }[];
            }> {
                return request('POST', `/api/imports/${id}/issues/bulk-resolve`, {
                    body: { issue_ids: issueIds, resolution },
                });
            },

            rebuildPlan(id: number): Promise<{ message: string }> {
                return request('POST', `/api/imports/${id}/rebuild-plan`);
            },

            apply(
                id: number,
                identity: { plan_revision: number; plan_digest: string },
            ): Promise<{ message: string; data: LegacyImportDetail }> {
                return request('POST', `/api/imports/${id}/apply`, { body: identity });
            },

            cancel(id: number): Promise<{ message: string }> {
                return request('POST', `/api/imports/${id}/cancel`);
            },
        },
    },

    support: {
        queues(): Promise<{ data: { id: number; name: string }[] }> {
            return request('GET', '/api/support/queues');
        },

        inbox(params: Record<string, any> = {}): Promise<{ data: any[]; current_page: number; last_page: number; total: number; per_page: number }> {
            return request('GET', '/api/support/inbox', { query: params });
        },

        conversation(id: number, params: Record<string, any> = {}): Promise<{ data: any }> {
            return request('GET', `/api/support/conversations/${id}`, { query: params });
        },

        createConversation(payload: Record<string, any>): Promise<{ data: any }> {
            return request('POST', '/api/support/conversations', { body: payload });
        },

        sendMessage(conversationId: number, payload: { body_text: string; attachment_ids?: number[] }): Promise<{ data: any }> {
            return request('POST', `/api/support/conversations/${conversationId}/messages`, { body: payload });
        },

        sendNote(conversationId: number, payload: { body_text: string }): Promise<{ data: any }> {
            return request('POST', `/api/support/conversations/${conversationId}/notes`, { body: payload });
        },

        uploadAttachment(conversationId: number, file: File): Promise<{ data: any }> {
            const formData = new FormData();
            formData.append('file', file);
            return request('POST', `/api/support/conversations/${conversationId}/attachments`, { body: formData });
        },

        sendMessageWithAttachment(conversationId: number, payload: { body_text: string; attachment_ids: number[] }): Promise<{ data: any }> {
            return request('POST', `/api/support/conversations/${conversationId}/messages`, { body: payload });
        },

        changePriority(conversationId: number, priority: string): Promise<{ data: any }> {
            return request('POST', `/api/support/conversations/${conversationId}/priority`, { body: { priority } });
        },

        changeQueue(conversationId: number, payload: { queue_id: number; assignee_user_id?: number | null }): Promise<{ data: any }> {
            return request('POST', `/api/support/conversations/${conversationId}/queue`, { body: payload });
        },

        resolveConversation(conversationId: number): Promise<{ data: any }> {
            return request('POST', `/api/support/conversations/${conversationId}/resolve`);
        },

        closeConversation(conversationId: number): Promise<{ data: any }> {
            return request('POST', `/api/support/conversations/${conversationId}/close`);
        },

        reopenConversation(conversationId: number): Promise<{ data: any }> {
            return request('POST', `/api/support/conversations/${conversationId}/reopen`);
        },

        assignConversation(conversationId: number, userId: number | null): Promise<{ data: any }> {
            return request('POST', `/api/support/conversations/${conversationId}/assign`, { body: { user_id: userId } });
        },

        loadMoreMessages(conversationId: number, beforeId: number, perPage: number = 50): Promise<{ data: { messages: any[] } }> {
            return request('GET', `/api/support/conversations/${conversationId}`, { query: { before_id: beforeId, per_page: perPage } });
        },
    },

    push: {
        vapidPublicKey(): Promise<{ public_key: string }> {
            return request<{ public_key: string }>('GET', '/api/push/vapid-public-key');
        },

        storeSubscription(payload: {
            endpoint: string;
            keys: { p256dh: string; auth: string };
        }): Promise<{ id: number }> {
            return request<{ id: number }>('POST', '/api/push/subscriptions', { body: payload });
        },

        destroySubscription(id: number): Promise<{ message: string }> {
            return request<{ message: string }>('DELETE', `/api/push/subscriptions/${id}`);
        },
    },
} as const satisfies ApiClient;

/** Re-exported so components do not need to know where the user type lives. */
export type { AuthUser };

/** Business API surface for A02-A05 compatibility */
export const businessApi = api.businessApi;

/** Re-export ListParams for use in other files */
export interface ListParams {
    search?: string;
    status?: string;
    per_page?: number;
    page?: number;
    [key: string]: string | number | boolean | null | undefined;
}