import { request } from '@/services/http';

import type {
    AppNotification,
    ClientAccountPayload,
    CalendarEvent,
    ClientDocument,
    ClientDocumentRequest,
    ClientProfileUpdateRequest,
    ClientDocumentType,
    GeneratedReport,
    Novelty,
    OperationalTask,
    Paginated,
    PlanillaDetail,
    PlanillaPreview,
    PlanillaSummary,
    PlanillaValidation,
    PortalAffiliation,
    PortalDocument,
    PortalDocumentRequest,
    PortalHome,
    PortalProfile,
    PortalRelationship,
    ReportPayload,
    ReportSchedule,
    VocabularyOption,
} from '@/types/api';

/**
 * A05 — the operational layer.
 *
 * Every call here is a thin, typed wrapper. Nothing computes a business figure on
 * the client: planilla totals come from the server's derived sum, and the client's
 * account comes from A03's service through a portal endpoint. A number calculated
 * in Vue would be a second answer to a question the server already answers.
 */
export const a05 = {
    /* --- planillas (§15–§27) ------------------------------------------- */

    planillas: {
        list(filters: Record<string, string | number | undefined> = {}): Promise<{ data: PlanillaSummary[]; pagination: Paginated<PlanillaSummary>['pagination'] }> {
            return request('GET', '/api/planillas', { query: filters });
        },

        vocabulary(): Promise<{ operators: VocabularyOption[]; statuses: VocabularyOption[] }> {
            return request('GET', '/api/planillas/vocabulary');
        },

        show(id: number): Promise<PlanillaDetail> {
            return request('GET', `/api/planillas/${id}`);
        },

        /**
         * §20: read-only. Nothing is created, and the digest it returns is what makes
         * the later creation describe the same people.
         */
        preview(input: { period_id: number; company_id: number }): Promise<PlanillaPreview> {
            return request('POST', '/api/planillas/preview', { body: input });
        },

        create(input: {
            period_id: number;
            company_id: number;
            operator: string;
            operator_other_name?: string | null;
            notes?: string | null;
            source_digest: string;
        }): Promise<PlanillaDetail> {
            return request('POST', '/api/planillas', { body: input });
        },

        /**
         * §23: the operational values of a draft line. `liquidated_amount_cop` is an
         * integer; the server refuses a float rather than rounding it.
         */
        updateLine(
            sheetId: number,
            lineId: number,
            changes: { liquidated_amount_cop?: number | null; included?: boolean; exclusion_reason?: string | null },
        ): Promise<PlanillaDetail> {
            return request('PATCH', `/api/planillas/${sheetId}/lines/${lineId}`, { body: changes });
        },

        updateSheet(
            id: number,
            changes: { operator?: string; operator_other_name?: string | null; notes?: string | null },
        ): Promise<PlanillaDetail> {
            return request('PATCH', `/api/planillas/${id}`, { body: changes });
        },

        validate(id: number): Promise<PlanillaValidation> {
            return request('POST', `/api/planillas/${id}/validate`);
        },

        submit(id: number, input: { sheet_number?: string | null; reference?: string | null; submitted_on: string }): Promise<PlanillaDetail> {
            return request('POST', `/api/planillas/${id}/submit`, { body: input });
        },

        markPaid(id: number, input: { paid_on: string }): Promise<PlanillaDetail> {
            return request('POST', `/api/planillas/${id}/mark-paid`, { body: input });
        },

        cancel(id: number, reason: string): Promise<PlanillaDetail> {
            return request('POST', `/api/planillas/${id}/cancel`, { body: { reason } });
        },

        returnToDraft(id: number): Promise<PlanillaDetail> {
            return request('POST', `/api/planillas/${id}/return-to-draft`);
        },

        uploadFile(id: number, file: File, kind: string): Promise<PlanillaFilePayload> {
            const form = new FormData();
            form.append('file', file);
            form.append('kind', kind);

            return request('POST', `/api/planillas/${id}/files`, { body: form });
        },
    },

    /* --- novelties, tasks, calendar (§28–§33) ------------------------- */

    novelties: {
        list(filters: Record<string, string | number | undefined> = {}): Promise<Paginated<Novelty>> {
            return request('GET', '/api/novelties', { query: filters });
        },

        vocabulary(): Promise<{ categories: VocabularyOption[]; statuses: VocabularyOption[] }> {
            return request('GET', '/api/novelties/vocabulary');
        },

        store(input: {
            client_id: number;
            company_id?: number | null;
            category: string;
            title: string;
            details?: string | null;
            occurred_on?: string | null;
        }): Promise<Novelty> {
            return request('POST', '/api/novelties', { body: input });
        },

        resolve(id: number): Promise<Novelty> {
            return request('POST', `/api/novelties/${id}/resolve`);
        },

        cancel(id: number, reason: string): Promise<Novelty> {
            return request('POST', `/api/novelties/${id}/cancel`, { body: { reason } });
        },
    },

    tasks: {
        list(filters: Record<string, string | number | undefined> = {}): Promise<Paginated<OperationalTask>> {
            return request('GET', '/api/tasks', { query: filters });
        },

        vocabulary(): Promise<{ priorities: VocabularyOption[]; statuses: VocabularyOption[] }> {
            return request('GET', '/api/tasks/vocabulary');
        },

        store(input: {
            client_id?: number | null;
            title: string;
            description?: string | null;
            assigned_to: number;
            priority: string;
            due_on?: string | null;
            reminder_at?: string | null;
        }): Promise<OperationalTask> {
            return request('POST', '/api/tasks', { body: input });
        },

        complete(id: number): Promise<OperationalTask> {
            return request('POST', `/api/tasks/${id}/complete`);
        },

        cancel(id: number): Promise<OperationalTask> {
            return request('POST', `/api/tasks/${id}/cancel`);
        },

        reassign(id: number, assignedTo: number): Promise<OperationalTask> {
            return request('POST', `/api/tasks/${id}/reassign`, { body: { assigned_to: assignedTo } });
        },
    },

    calendar: {
        /**
         * The range is bounded server-side to three months, so the browser never asks
         * for years of events and the server never has to refuse a huge one.
         */
        events(start: string, end: string): Promise<{ events: CalendarEvent[] }> {
            return request('GET', '/api/calendar', { query: { start, end } });
        },
    },

    /* --- documents (§35–§40) ------------------------------------------- */

    documents: {
        types(): Promise<{ data: ClientDocumentType[] }> {
            return request('GET', '/api/document-types');
        },

        storeType(input: {
            name: string;
            slug: string;
            description?: string | null;
            retention_days?: number | null;
            client_visible_default?: boolean;
        }): Promise<{ id: number }> {
            return request('POST', '/api/document-types', { body: input });
        },

        list(filters: Record<string, string | number | undefined> = {}): Promise<Paginated<ClientDocument>> {
            return request('GET', '/api/documents', { query: filters });
        },

        upload(input: {
            client_id: number;
            document_type_id: number;
            document_request_id?: number | null;
            title: string;
            visibility: string;
            file: File;
        }): Promise<ClientDocument> {
            const { file, ...rest } = input;
            const form = new FormData();

            for (const [key, value] of Object.entries(rest)) {
                if (value !== null && value !== undefined) {
                    form.append(key, String(value));
                }
            }

            form.append('file', file);

            return request('POST', '/api/documents', { body: form });
        },

        listRequests(filters: Record<string, string | number | undefined> = {}): Promise<Paginated<ClientDocumentRequest>> {
            return request('GET', '/api/document-requests', { query: filters });
        },

        storeRequest(input: {
            client_id: number;
            document_type_id: number;
            title: string;
            instructions?: string | null;
            due_on?: string | null;
        }): Promise<ClientDocumentRequest> {
            return request('POST', '/api/document-requests', { body: input });
        },

        receive(id: number): Promise<ClientDocumentRequest> {
            return request('POST', `/api/document-requests/${id}/receive`);
        },

        review(id: number, decision: 'approve' | 'reject', note?: string | null): Promise<ClientDocumentRequest> {
            return request('POST', `/api/document-requests/${id}/review`, { body: { decision, note } });
        },

        cancelRequest(id: number, reason: string): Promise<ClientDocumentRequest> {
            return request('POST', `/api/document-requests/${id}/cancel`, { body: { reason } });
        },
    },

    /* --- staff review of a client's profile proposal (§44) --------------- */

    clients: {
        updateRequests(clientId: number): Promise<{ data: ClientProfileUpdateRequest[] }> {
            return request('GET', '/api/client-profile-update-requests', { query: { client_id: clientId } });
        },

        approveUpdateRequest(id: number): Promise<{ id: number; status: string }> {
            return request('POST', `/api/client-profile-update-requests/${id}/approve`);
        },

        rejectUpdateRequest(id: number, note: string): Promise<{ id: number; status: string }> {
            return request('POST', `/api/client-profile-update-requests/${id}/reject`, { body: { note } });
        },
    },

    /* --- portal (§41–§49) ---------------------------------------------- */

    portal: {
        home(): Promise<PortalHome> {
            return request('GET', '/api/portal/home');
        },

        profile(): Promise<PortalProfile> {
            return request('GET', '/api/portal/profile');
        },

        relationships(): Promise<{ relationships: PortalRelationship[]; affiliations: PortalAffiliation[] }> {
            return request('GET', '/api/portal/relationships');
        },

        /**
         * §43/§44: a proposal, never a direct write. The client master is untouched
         * until staff approve it through A02's own update action.
         */
        submitUpdateRequest(changes: Record<string, string>): Promise<{ id: number; status: string; proposed_changes: Record<string, string> }> {
            return request('POST', '/api/portal/profile/update-request', { body: changes });
        },

        updateRequests(): Promise<{ data: ClientProfileUpdateRequest[] }> {
            return request('GET', '/api/portal/profile/update-requests');
        },

        financialAccount(): Promise<ClientAccountPayload> {
            return request('GET', '/api/portal/financial-account');
        },

        documents(): Promise<{ data: PortalDocument[] }> {
            return request('GET', '/api/portal/documents');
        },

        documentRequests(): Promise<{ data: PortalDocumentRequest[] }> {
            return request('GET', '/api/portal/document-requests');
        },

        uploadResponse(id: number, file: File): Promise<{ id: number; title: string; review_status: string }> {
            const form = new FormData();
            form.append('file', file);

            return request('POST', `/api/portal/document-requests/${id}/upload`, { body: form });
        },
    },

    /* --- reports (§51–§57) --------------------------------------------- */

    reports: {
        vocabulary(): Promise<{ types: VocabularyOption[]; formats: VocabularyOption[] }> {
            return request('GET', '/api/reports/vocabulary');
        },

        /**
         * One filter contract for the screen and for every export, which is why the
         * download URL is built from the same object the table was loaded with.
         */
        run(type: string, filters: Record<string, string | number | undefined> = {}): Promise<ReportPayload> {
            return request('GET', '/api/reports', { query: { type, ...filters } });
        },

        generated(): Promise<Paginated<GeneratedReport>> {
            return request('GET', '/api/reports/generated');
        },

        schedules(): Promise<Paginated<ReportSchedule>> {
            return request('GET', '/api/report-schedules');
        },

        storeSchedule(input: Record<string, string | number | boolean | null>): Promise<ReportSchedule> {
            return request('POST', '/api/report-schedules', { body: input });
        },

        deactivateSchedule(id: number): Promise<ReportSchedule> {
            return request('POST', `/api/report-schedules/${id}/deactivate`);
        },
    },

    /* --- notifications (§59) ------------------------------------------- */

    notifications: {
        recent(): Promise<{ data: AppNotification[]; unread: number }> {
            return request('GET', '/api/notifications');
        },

        markRead(id: string): Promise<{ unread: number }> {
            return request('POST', `/api/notifications/${id}/read`);
        },

        markAllRead(): Promise<{ unread: number }> {
            return request('POST', '/api/notifications/read-all');
        },
    },
} as const;

interface PlanillaFilePayload {
    id: number;
    kind: string;
    original_name: string;
    size_bytes: number;
    created_at: string;
}
