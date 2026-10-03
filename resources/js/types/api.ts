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

/**
 * The dashboard figures.
 *
 * The relationship, affiliation and catalogue figures are optional because the
 * server withholds them from roles that may not read the section they describe.
 * A figure the role cannot read is absent rather than zero, because zero is a claim
 * about the portfolio and the interface must not make that claim on the server's
 * behalf.
 */
export interface PortfolioCounts {
    active_clients?: number;
    inactive_clients?: number;
    active_companies?: number;
    data_quality_issues?: number;
    data_quality_warnings?: number;
    active_relationships?: number;
    active_affiliations?: number;
    catalogue_entities?: number;
    authorised_parallel_relationships?: number;
}

export interface PortfolioPayload {
    /** False when the user's role may not see the portfolio at all. */
    visible: boolean;
    counts?: PortfolioCounts;
    /**
     * A03: the financial position, beside the directory counts it belongs with.
     *
     * Optional because the server withholds it from a role that may not read the
     * portfolio, exactly as it withholds the counts. An absent figure means withheld
     * and is never drawn as a zero.
     */
    financial?: {
        outstanding_balance_cop: number;
        overdue_balance_cop: number;
        total_paid_cop: number;
        clients_with_debt: number;
        payments_requiring_reconciliation: number;
    };
    /** The per code figures behind the totals, filtered by the same permissions. */
    quality?: Record<string, number>;
    /** Only present with `relationships.view`. */
    multiple_companies?: number;
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
    portfolio: PortfolioPayload;
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

/* -------------------------------------------------------------------------
 | A02: the business domain
 |------------------------------------------------------------------------- */

export type RecordStatus = 'active' | 'inactive';

export type DocumentType = 'CC' | 'CE' | 'TI' | 'PPT' | 'PASSPORT' | 'OTHER';

export type SocialSecurityType = 'EPS' | 'AFP' | 'ARL' | 'CCF';

export interface Option<T extends string | number = string> {
    value: T;
    label: string;
}

export interface Pagination {
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
}

export interface CompanySummary {
    id: number;
    legal_name: string;
    trade_name: string | null;
    display_name: string;
    status: RecordStatus;
    status_label: string;
}

export interface ClientListItem {
    id: number;
    document_type: DocumentType;
    document_type_short: string;
    document_number: string;
    document_label: string;
    full_name: string;
    first_names: string;
    last_names: string;
    email: string | null;
    phone: string | null;
    status: RecordStatus;
    status_label: string;
    /**
     * Absent, not null, for a role that may read clients but not relationships.
     * "No companies" and "not allowed to see them" are different answers, so the
     * column hides itself instead of claiming one of them.
     */
    companies_count?: number | null;
    companies?: CompanySummary[];
}

export interface Client extends ClientListItem {
    address: string | null;
    city: string | null;
    department: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface Company extends CompanySummary {
    tax_id: string | null;
    tax_id_label: string | null;
    verification_digit: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    city: string | null;
    department: string | null;
    /** Absent unless the viewer may read relationships: it is relationship data. */
    active_clients_count?: number | null;
    total_clients_count?: number | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface Assignment {
    id: number;
    client_id: number;
    company_id: number;
    company: CompanySummary | null;
    /** Present only on the screens that are about the client, not the company. */
    client: { id: number; full_name: string } | null;
    started_on: string | null;
    ended_on: string | null;
    is_active: boolean;
    job_title: string | null;
    notes: string | null;
    is_parallel: boolean;
    parallel_reason: string | null;
    parallel_authorized_at: string | null;
}

export interface EntitySummary {
    id: number;
    type: SocialSecurityType;
    type_label: string;
    name: string;
    code: string | null;
    status: RecordStatus;
    status_label: string;
}

export interface Affiliation {
    id: number;
    client_id: number;
    social_security_entity_id: number;
    entity: EntitySummary | null;
    client_company_assignment_id: number | null;
    type: SocialSecurityType;
    type_label: string;
    type_full_label: string;
    started_on: string | null;
    ended_on: string | null;
    is_active: boolean;
    /** Stored as the integer 1..5, never as a numeral. */
    arl_risk_class: number | null;
    arl_risk_label: string | null;
    notes: string | null;
}

export interface SocialSecurityEntity extends EntitySummary {
    type_full_label: string;
    tax_id: string | null;
    /** Absent unless the viewer may read affiliations: it is affiliation data. */
    affiliations_count?: number | null;
    active_affiliations_count?: number | null;
    created_at: string | null;
    updated_at: string | null;
}

export type DataQualitySeverity = 'error' | 'warning' | 'notice';

export interface DataQualityFinding {
    code: string;
    label: string;
    severity: DataQualitySeverity;
    severity_label: string;
    message: string;
    suggestion: string | null;
    blocking: boolean;
}

export interface TimelineEntry {
    action: string;
    subject: string | null;
    at: string;
    metadata: Record<string, unknown> | null;
}

export interface ClientListPayload {
    clients: ClientListItem[];
    pagination: Pagination;
    filters: { search: string | null; status: string | null; company_id: number | null };
}

/**
 * A section that can be withheld.
 *
 * `visible: false` means the role may not read it, which is not the same as it
 * being empty, so the interface says so instead of drawing an empty list that
 * looks like an answer.
 */
export interface ReadSection<T> {
    visible: boolean;
    active?: T[];
    history?: T[];
    history_count?: number;
}

export interface ClientDetailPayload {
    client: Client;
    /**
     * The ARL risk classes, from the domain enum. The interface does not keep its
     * own copy: a second list is a second thing to be wrong about what a class means.
     */
    risk_options: { value: number; label: string }[];
    companies: ReadSection<Assignment>;
    affiliations: ReadSection<Affiliation>;
    history: TimelineEntry[];
    data_quality: DataQualityFinding[];
}

export interface CompanyListPayload {
    companies: Company[];
    pagination: Pagination;
    filters: { search: string | null; status: string | null };
}

export interface CompanyDetailPayload {
    company: Company;
    clients: ReadSection<Assignment>;
    data_quality: DataQualityFinding[];
}

export interface EntityListPayload {
    entities: SocialSecurityEntity[];
    pagination: Pagination;
    filters: { search: string | null; status: string | null; type: string | null };
}

/** The three ways an existing relationship can be resolved, from the server. */
export interface ResolutionOption {
    value: string;
    label: string;
    description: string;
    requires_reason?: boolean;
}

export interface ConflictPayload {
    message: string;
    code: string;
    options: ResolutionOption[];
    open_assignments?: number[];
    existing_affiliation_id?: number;
    existing_entity_id?: number;
    open_relationships_count?: number;
    open_affiliations_count?: number;
    active_clients_count?: number;
    errors?: FieldErrors;
}

// --- A03: periods, obligations, payments and receivables ---------------------
//
// Money is a whole number of pesos everywhere, on the wire and in the interface.
// It is never a formatted string sent from the server and never a float: the value
// that arrives is the value that is owed, and the formatting is this application's
// business and nobody else's.

export type PeriodStatus = 'open' | 'closed';

/** Where an obligation's amount came from, for the interface to explain itself. */
export type ObligationSource = 'generated' | 'manual' | 'imported';

export type SettlementState = 'paid' | 'partial' | 'pending';

export type AgingBucketKey =
    | 'not_due'
    | '1_30'
    | '31_60'
    | '61_90'
    | 'over_90';

export type TrafficLightKey = 'green' | 'yellow' | 'orange' | 'red';

export type PaymentMethodKey = 'cash' | 'bank_transfer' | 'deposit' | 'other';

export type ReconciliationStateKey =
    | 'unallocated'
    | 'partially_allocated'
    | 'fully_allocated'
    | 'voided';

export type AdjustmentTypeKey = 'correction' | 'discount' | 'surcharge';

export interface PeriodSummary {
    id: number;
    key: string;
    label: string;
    period_month: string;
    starts_on: string;
    ends_on_exclusive: string;
    status: PeriodStatus;
    status_label: string;
    opened_at: string | null;
    closed_at: string | null;
    reopened_at: string | null;
    last_reopen_reason: string | null;
    generation_performed_at: string | null;
    obligation_count: number;
    total_base_cop: number;
    total_effective_cop: number;
    total_paid_cop: number;
    total_balance_cop: number;
    accepts_structural_change?: boolean;
    accepts_financial_activity?: boolean;
}

export interface PeriodListPayload {
    items: PeriodSummary[];
    pagination: Pagination;
    /** The period the system considers current, or null when none is open. */
    current: PeriodSummary | null;
}

export interface ObligationTotals {
    base_amount_cop: number;
    adjustments_cop: number;
    effective_amount_cop: number;
    paid_amount_cop: number;
    balance_cop: number;
    settlement_state: SettlementState;
    is_overdue: boolean;
    days_late: number;
    aging_bucket: AgingBucketKey;
}

export interface ObligationSummary extends ObligationTotals {
    id: number;
    period_id: number;
    period_key?: string | null;
    period_label?: string | null;
    client_id: number;
    client_name?: string | null;
    company_id: number;
    company_name?: string | null;
    due_on: string | null;
    source: ObligationSource;
    source_label: string;
    settlement_state_label?: string;
    aging_bucket_label?: string;
}

export interface AdjustmentSummary {
    id: number;
    obligation_id: number;
    type: AdjustmentTypeKey;
    type_label: string;
    delta_cop: number;
    reason: string;
    reverses_adjustment_id: number | null;
    reversed_at: string | null;
    reversed_by: number | null;
    reversal_reason: string | null;
    created_at: string;
}

export interface GenerationFinding {
    code: string;
    message: string;
    context?: Record<string, unknown>;
}

export interface ObligationCandidate {
    client_id: number;
    company_id: number;
    client_name: string | null;
    company_name: string | null;
    assignment_id: number;
    /** The snapshot that would be written, and null when the configuration is missing. */
    amount_cop: number | null;
    /**
     * The rule that resolved this candidate, and what it produced. Null `due_on`
     * with `resolved: false` is how a missing cutoff says so.
     */
    cutoff: {
        resolved: boolean;
        due_on: string | null;
        source: string | null;
        source_label: string | null;
        cutoff_rule_id: number | null;
        cutoff_day: number | null;
        month_offset: number | null;
    };
    rate_id: number | null;
    already_exists: boolean;
    will_be_created: boolean;
    blockers: GenerationFinding[];
}

export interface GenerationPreview {
    period: { key: string; label: string };
    candidate_count: number;
    creatable_count: number;
    resolved_count: number;
    blocker_count: number;
    total_amount_cop: number;
    /** False when at least one blocker stands: the month cannot be generated yet. */
    can_generate: boolean;
    candidates: ObligationCandidate[];
    blockers: GenerationFinding[];
    warnings: GenerationFinding[];
}

export interface GenerationPreviewPayload {
    period: PeriodSummary;
    preview: GenerationPreview;
}

export interface GenerationResultPayload {
    message: string;
    result: {
        created: number;
        skipped: number;
        total_amount_cop: number;
    };
    period: PeriodSummary;
}

export interface PaymentSummary {
    id: number;
    client_id: number;
    client_name: string | null;
    amount_cop: number;
    received_on: string | null;
    method: PaymentMethodKey;
    method_label: string;
    reference: string | null;
    notes: string | null;
    allocated_amount_cop: number;
    unallocated_amount_cop: number;
    reconciliation_state: ReconciliationStateKey;
    reconciliation_state_label: string;
    requires_reconciliation: boolean;
    is_voided: boolean;
    voided_at: string | null;
    void_reason: string | null;
    allocations?: PaymentAllocationSummary[];
}

export interface PaymentAllocationSummary {
    id: number;
    payment_id: number;
    obligation_id: number;
    obligation_label?: string | null;
    amount_cop: number;
    reversed_at: string | null;
    reversed_by: number | null;
    reversal_reason: string | null;
    created_at: string;
}

export interface PaymentListPayload {
    items: PaymentSummary[];
    pagination: Pagination;
    filters?: Record<string, string | null>;
}

/** A payment that looks like one already recorded, offered for confirmation. */
export interface PossibleDuplicate {
    id: number;
    amount_cop: number;
    received_on: string | null;
    reference: string | null;
    method: PaymentMethodKey;
}

export interface DuplicateWarningPayload {
    message: string;
    code: 'possible_duplicate';
    possible_duplicates: PossibleDuplicate[];
}

export interface AutoAllocationLine {
    obligation_id: number;
    period_key: string;
    period_label: string | null;
    due_on: string | null;
    balance_cop: number;
    /** How much of this obligation the payment would reach. */
    would_apply_cop: number;
}

export interface AutoAllocationPlan {
    payment_id: number;
    available_cop: number;
    would_apply_count: number;
    would_apply_cop: number;
    would_remain_unallocated_cop: number;
    allocations: AutoAllocationLine[];
}

export interface ReceivableRow {
    client_id: number;
    full_name: string;
    document_label: string;
    company_names: string[];
    balance_cop: number;
    paid_amount_cop: number;
    overdue_balance_cop: number;
    open_obligations_count: number;
    overdue_obligations_count: number;
    owed_periods: string[];
    oldest_due_on: string | null;
    aging_bucket: AgingBucketKey;
    traffic_light: TrafficLightKey;
    traffic_light_label: string;
    traffic_light_reason: string;
}

export interface ReceivablesTotals {
    outstanding_balance_cop: number;
    overdue_balance_cop: number;
    total_effective_obligations_cop: number;
    total_paid_cop: number;
    clients_with_debt: number;
    open_obligations_count: number;
    overdue_obligations_count: number;
    unallocated_credit_cop: number;
    payments_requiring_reconciliation: number;
}

export interface ReceivablesListPayload {
    items: ReceivableRow[];
    summary: ReceivablesTotals;
    total: number;
    page: number;
    per_page: number;
    last_page: number;
}

export interface ClientAccountPayload {
    client: {
        id: number;
        full_name: string;
        document_label: string;
    };
    as_of: string;
    summary: {
        total_effective_obligations_cop: number;
        total_paid_cop: number;
        outstanding_balance_cop: number;
        overdue_balance_cop: number;
        unallocated_credit_cop: number;
        open_obligations_count: number;
        overdue_obligations_count: number;
        owed_periods: string[];
    };
    obligations: Array<
        ObligationSummary & {
            aging_bucket: AgingBucketKey;
            aging_bucket_label: string;
            settlement_state_label: string;
        }
    >;
}

export interface CutoffRuleSummary {
    id: number;
    scope: 'general' | 'company' | 'client';
    scope_label: string;
    company_id: number | null;
    company_name: string | null;
    client_id: number | null;
    client_name: string | null;
    effective_month: string;
    effective_month_label: string;
    cutoff_day: number;
    month_offset: number;
    month_offset_label: string;
    /** A worked example, so the rule can be checked without doing the arithmetic. */
    example_due_on: string;
    example_period: string;
    notes: string | null;
    /** A generated month already quotes this rule, so its day cannot change. */
    in_use: boolean;
}

export interface CutoffRuleListPayload {
    items: CutoffRuleSummary[];
    pagination: Pagination;
    scopes: VocabularyOption[];
    offsets: VocabularyOption[];
}

export interface RateSummary {
    id: number;
    client_id: number;
    client_name: string;
    company_id: number;
    company_name: string;
    effective_month: string;
    effective_month_label: string;
    amount_cop: number;
    notes: string | null;
    /** A generated obligation already quotes this value, so it cannot change. */
    in_use: boolean;
}

export interface RateListPayload {
    items: RateSummary[];
    pagination: Pagination;
}

export interface RateHistoryPayload {
    client_id: number;
    company_id: number;
    current: RateSummary | null;
    history: RateSummary[];
}

export interface VocabularyOption {
    value: string;
    label: string;
}

export interface BillingVocabularyPayload {
    aging_buckets: VocabularyOption[];
    traffic_lights: VocabularyOption[];
    payment_methods: VocabularyOption[];
    settlement_states: VocabularyOption[];
    period_statuses: VocabularyOption[];
}
