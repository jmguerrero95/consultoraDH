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
