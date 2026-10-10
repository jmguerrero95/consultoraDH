<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\Api\BillingConfigurationController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClientProfileUpdateRequestController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\Internal\SupportEmailIngressController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NoveltyController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PeriodController;
use App\Http\Controllers\Api\PlanillaController;
use App\Http\Controllers\Api\Portal\PortalDocumentController;
use App\Http\Controllers\Api\Portal\PortalProfileController;
use App\Http\Controllers\Api\Portal\PortalRelationshipController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\ReceivableController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SocialSecurityEntityController;
use App\Http\Controllers\Api\SupportConversationController;
use App\Http\Controllers\Api\SupportInboundEmailController;
use App\Http\Controllers\Api\SupportInboxController;
use App\Http\Controllers\Api\SupportPresenceController;
use App\Http\Controllers\Api\SupportQueueController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TelegramController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Route-model parameters are numeric
|--------------------------------------------------------------------------
|
| A03-R1. `/payments/vocabulary` and `/payments/{payment}` collided: the literal segment
| was swallowed by the parameter and the request reached the database as a lookup for a
| payment with the id "vocabulary", which is a 500 from PostgreSQL about an invalid bigint
| rather than a vocabulary.
|
| Declaring a pattern per parameter would have left the next such route to collide the same
| way, and there are many. Constraining the parameter **names** once here is the fix that
| holds for routes that do not exist yet, and it also means a request for `/payments/abc`
| is a 404 rather than a cast failure.
|
| Every parameter bound to a model in this API is a database identifier, so none of them can
| legitimately be anything else. Adding a new model-bound route therefore cannot reintroduce
| the collision.
|
*/
foreach ([
    'obligation',
    'adjustment',
    'assignment',
    'affiliation',
    'allocation',
    'client',
    'company',
    'entity',
    'payment',
    'period',
    'rate',
    'rule',
    'user',
    'planilla',
    'line',
    'novelty',
    'task',
    'document',
    'documentRequest',
    'updateRequest',
    'reportSchedule',
    'generatedReport',
    'supportQueue',
    'supportConversation',
    'automationRule',
    'telegramEndpoint',
    'conversation',
    'queue',
    'endpoint',
    'rule',
    'email',
    'subscription',
    'attachment',
] as $parameter) {
    Route::pattern($parameter, '[0-9]+');
}

/*
|--------------------------------------------------------------------------
| JSON API
|--------------------------------------------------------------------------
|
| These routes are registered with the `web` middleware group, not the default
| stateless `api` group. That is what makes cookie based session
| authentication and CSRF verification work for the single page application.
| See bootstrap/app.php and docs/SECURITY.md.
|
| Authorisation is enforced on the server. Each A02 route names the permission it
| needs, in the `can:` middleware, so the rule sits next to the route it protects
| rather than in a controller. The interface hides what the user cannot open;
| that is presentation only.
|
*/

Route::get('/health', HealthController::class)->name('api.health');

/*
| Public: authentication.
|
| Three separate policies, never shared. Password recovery requests are not
| sign in attempts, and must not be able to spend the sign in budget: otherwise
| anyone who knows an administrator's address could keep them locked out by
| asking for reset mails, without ever guessing a password. The reverse also
| holds, a flood of wrong passwords does not consume the recovery budget.
|
| Each policy counts two ways at once, per client address and per normalised
| account, so a single key can neither be sprayed across accounts nor walked
| across machines. See AppServiceProvider for the values and the reasoning.
*/

Route::middleware(['throttle:login-attempt', 'throttle:login-account'])->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])->name('api.auth.login');
});

Route::middleware(['throttle:recovery-attempt', 'throttle:recovery-account'])->group(function (): void {
    Route::post('/auth/forgot-password', [AuthController::class, 'sendPasswordResetLink'])
        ->name('api.auth.forgot-password');
});

Route::middleware(['throttle:reset-attempt', 'throttle:reset-account'])->group(function (): void {
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
        ->name('api.auth.reset-password');
});

// =================================================================
// A06: Internal inbound email ingestion (HMAC protected) — OUTSIDE AUTH
// =================================================================
//
// This endpoint must be accessible to external email services (SendGrid, Mailgun, etc.)
// that authenticate via HMAC signature only, not via Laravel session cookies.
// It is deliberately placed OUTSIDE the authenticated route group above.

Route::prefix('internal')->group(function (): void {
    Route::post('/support-email/inbound', [SupportEmailIngressController::class, 'ingest'])
        ->middleware(['throttle:support-inbound', 'support.email.hmac'])
        ->name('api.internal.support-email.inbound');
});

// --- Authenticated ---------------------------------------------------------
// The general API limiter is applied here, so a valid session cannot be used to
// hammer the application, and `user.active` so a session opened while the
// account was active stops working the moment it is suspended.
Route::middleware(['auth', 'auth.session', 'user.active', 'throttle:api'])->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

    Route::get('/dashboard', DashboardController::class)->name('api.dashboard');

    Route::get('/profile', [ProfileController::class, 'show'])->name('api.profile.show');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('api.profile.update');
    Route::put('/profile/email', [ProfileController::class, 'updateEmail'])->name('api.profile.email');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('api.profile.password');

    // Restricted to roles that may inspect the application configuration.
    Route::middleware('can:'.SettingsController::PERMISSION)
        ->get('/settings', SettingsController::class)
        ->name('api.settings');

    // --- A02: clients -------------------------------------------------------
    //
    // Read and write are separate permissions. A role may consult the portfolio
    // without being able to change it, which is the normal arrangement for
    // support and collections work.
    Route::middleware('can:clients.view')->group(function (): void {
        Route::get('/clients', [ClientController::class, 'index'])->name('api.clients.index');
        Route::get('/clients/{client}', [ClientController::class, 'show'])->name('api.clients.show');

    });

    // The nested read endpoints need the parent's permission **and** the section's.
    //
    // They were inside the `clients.view` group, which made `relationships.view` a
    // decoration on the client's own screen: anything that could open the client
    // could also open this URL and read the employment history the screen had just
    // refused to show. A nested resource is not automatically readable by whoever
    // can read its parent.
    //
    // Two `can:` middlewares rather than one with a comma: Laravel treats
    // `can:a,b` as "either", which is the opposite of what this gate is for.
    Route::middleware(['can:clients.view', 'can:relationships.view'])
        ->get('/clients/{client}/companies', [ClientController::class, 'companies'])
        ->name('api.clients.companies');

    // Same for the affiliations, which are their own permission because they say
    // which health and pension entities somebody belongs to.
    Route::middleware(['can:clients.view', 'can:affiliations.view'])
        ->get('/clients/{client}/affiliations', [ClientController::class, 'affiliations'])
        ->name('api.clients.affiliations');

    Route::middleware('can:clients.create')->post('/clients', [ClientController::class, 'store'])
        ->name('api.clients.store');

    Route::middleware('can:clients.update')->patch('/clients/{client}', [ClientController::class, 'update'])
        ->name('api.clients.update');

    // Activation and deactivation are their own operation with their own
    // permission: making somebody inactive is a different decision from editing
    // their phone number.
    Route::middleware('can:clients.change_status')
        ->post('/clients/{client}/status', [ClientController::class, 'changeStatus'])
        ->name('api.clients.change-status');

    // --- A02: companies -----------------------------------------------------
    Route::middleware('can:companies.view')->group(function (): void {
        Route::get('/companies', [CompanyController::class, 'index'])->name('api.companies.index');
        Route::get('/companies/{company}', [CompanyController::class, 'show'])->name('api.companies.show');
    });

    Route::middleware('can:companies.create')->post('/companies', [CompanyController::class, 'store'])
        ->name('api.companies.store');

    Route::middleware('can:companies.update')->patch('/companies/{company}', [CompanyController::class, 'update'])
        ->name('api.companies.update');

    Route::middleware('can:companies.change_status')
        ->post('/companies/{company}/status', [CompanyController::class, 'changeStatus'])
        ->name('api.companies.change-status');

    // The company picker, for the client relationship form.
    Route::middleware('can:companies.view')
        ->get('/company-options', [ClientController::class, 'companyOptions'])
        ->name('api.company-options');

    // --- A02: relationships -------------------------------------------------
    //
    // There is no generic "edit this history row" endpoint. The rows are history,
    // and history is changed by closing one and opening another, so the only
    // operations exposed are those three.
    //
    // No read route for a single history row: the client's own endpoint returns
    // its relationships already, and a separate route for one row would invite
    // the interface to stitch two sources of truth together.
    Route::middleware('can:relationships.manage')->group(function (): void {
        Route::post('/clients/{client}/companies', [ClientController::class, 'linkCompany'])
            ->name('api.clients.companies.store');

        Route::post('/client-company-assignments/{assignment}/close', [ClientController::class, 'closeRelationship'])
            ->name('api.assignments.close');

        Route::post('/client-company-assignments/{assignment}/transfer', [ClientController::class, 'transfer'])
            ->name('api.assignments.transfer');
    });

    // --- A02: affiliations --------------------------------------------------
    Route::middleware('can:affiliations.manage')->group(function (): void {
        Route::post('/clients/{client}/affiliations', [ClientController::class, 'storeAffiliation'])
            ->name('api.clients.affiliations.store');

        Route::post('/client-affiliations/{affiliation}/close', [ClientController::class, 'closeAffiliation'])
            ->name('api.affiliations.close');

        Route::post('/client-affiliations/{affiliation}/change', [ClientController::class, 'changeAffiliationEntity'])
            ->name('api.affiliations.change');
    });

    // --- A02: social security entity catalogue ------------------------------
    Route::middleware('can:social_security_entities.view')->group(function (): void {
        Route::get('/social-security-entities', [SocialSecurityEntityController::class, 'index'])
            ->name('api.entities.index');
        Route::get('/social-security-entities/{entity}', [SocialSecurityEntityController::class, 'show'])
            ->name('api.entities.show');
    });

    Route::middleware('can:social_security_entities.manage')->group(function (): void {
        Route::post('/social-security-entities', [SocialSecurityEntityController::class, 'store'])
            ->name('api.entities.store');
        Route::patch('/social-security-entities/{entity}', [SocialSecurityEntityController::class, 'update'])
            ->name('api.entities.update');
        Route::post('/social-security-entities/{entity}/deactivate', [SocialSecurityEntityController::class, 'deactivate'])
            ->name('api.entities.deactivate');
    });

    // =================================================================
    // A03: periods, obligations, payments and receivables
    // =================================================================
    //
    // Every mutation is an explicit business action with its own name, never a
    // generic PATCH that sets a field. Closing a month, voiding a payment and
    // reversing an allocation are decisions somebody makes and somebody else
    // reads in the audit trail; a route that accepted `{"status": "..."}` would
    // make the decision invisible and unaudited.
    //
    // Read and write permissions are separate for the same reason A02 separated
    // them: knowing what a client owes is not authority to change it.

    // --- periods -------------------------------------------------------
    Route::middleware('can:periods.view')->group(function (): void {
        Route::get('/periods', [PeriodController::class, 'index'])->name('api.periods.index');
        Route::get('/periods/current', [PeriodController::class, 'current'])->name('api.periods.current');
        Route::get('/periods/{period}', [PeriodController::class, 'show'])->name('api.periods.show');

        Route::get('/periods/{period}/obligations', [PeriodController::class, 'obligations'])
            ->name('api.periods.obligations.index');
    });

    /*
    | The generation preview, and the permission it deliberately accepts.
    |
    | The preview is a calculation: it creates nothing and audits nothing, which is what
    | makes it safe to call on a live system as a confirmation step. It is also the
    | confirmation step for `POST .../generate`, which needs `obligations.generate`.
    |
    | A03-R1 found the guard here required `periods.view` while the controller separately
    | required `obligations.view`. That combination had one bad state and one bad
    | consequence:
    |
    |   * a role holding `obligations.generate` but not `obligations.view` could press a
    |     button whose confirmation dialog it was forbidden to open — the nonsensical
    |     state of being able to act but not to see;
    |   * a role holding `obligations.view` but not `periods.view` was refused a plan it
    |     was entitled to read.
    |
    | The documented contract, and the one implemented here:
    |
    |   preview   requires `obligations.generate` OR `obligations.view`
    |   generate  requires `obligations.generate`
    |
    | So a viewer can read the plan without being able to execute it — the interface
    | hides the confirm button, and the server refuses the write independently — and a
    | generator is never locked out of the confirmation it must pass through. The
    | obligation amounts a preview publishes are `obligations.view` information, so both
    | branches of the `any` are obligations permissions and neither widens who can read
    | a client's debt.
    |
    | `periods.view` is deliberately NOT enough on its own, and not part of the `any`.
    */
    Route::middleware('anyAbility:obligations.generate,obligations.view')
        ->post('/periods/{period}/obligations/preview', [PeriodController::class, 'previewObligations'])
        ->name('api.periods.obligations.preview');

    Route::middleware('can:periods.create')->group(function (): void {
        Route::post('/periods', [PeriodController::class, 'store'])->name('api.periods.store');
    });

    Route::middleware('can:periods.close')->group(function (): void {
        Route::post('/periods/{period}/close', [PeriodController::class, 'close'])
            ->name('api.periods.close');
    });

    // Reopening is exceptional and Operations does not hold it by default.
    Route::middleware('can:periods.reopen')->group(function (): void {
        Route::post('/periods/{period}/reopen', [PeriodController::class, 'reopen'])
            ->name('api.periods.reopen');
    });

    Route::middleware('can:obligations.generate')->group(function (): void {
        Route::post('/periods/{period}/obligations/generate', [PeriodController::class, 'generateObligations'])
            ->name('api.periods.obligations.generate');
    });

    // --- obligations and adjustments -----------------------------------
    Route::middleware('can:obligations.view')->group(function (): void {
        Route::get('/obligations/{obligation}/adjustments', [BillingConfigurationController::class, 'adjustments'])
            ->name('api.obligations.adjustments.index');
    });

    Route::middleware('can:obligations.adjust')->group(function (): void {
        Route::post('/obligations/{obligation}/adjustments', [BillingConfigurationController::class, 'storeAdjustment'])
            ->name('api.obligations.adjustments.store');

        // The types an operator may choose and the direction each accepts, from the same
        // enum the domain enforces. See `adjustmentVocabulary()`.
        Route::get('/obligation-adjustments/vocabulary', [BillingConfigurationController::class, 'adjustmentVocabulary'])
            ->name('api.obligation-adjustments.vocabulary');
        Route::post('/obligation-adjustments/{adjustment}/reverse', [BillingConfigurationController::class, 'reverseAdjustment'])
            ->name('api.obligation-adjustments.reverse');
    });

    // --- cutoff configuration ------------------------------------------
    Route::middleware('can:cutoffs.view')->group(function (): void {
        Route::get('/cutoff-rules', [BillingConfigurationController::class, 'cutoffRules'])
            ->name('api.cutoff-rules.index');
    });

    Route::middleware('can:cutoffs.manage')->group(function (): void {
        Route::post('/cutoff-rules', [BillingConfigurationController::class, 'storeCutoffRule'])
            ->name('api.cutoff-rules.store');
        Route::patch('/cutoff-rules/{rule}', [BillingConfigurationController::class, 'updateCutoffRule'])
            ->name('api.cutoff-rules.update');
    });

    // --- rates ---------------------------------------------------------
    Route::middleware('can:rates.view')->group(function (): void {
        Route::get('/rates', [BillingConfigurationController::class, 'rates'])->name('api.rates.index');
        Route::get('/clients/{client}/rates', [BillingConfigurationController::class, 'rateHistory'])
            ->name('api.clients.rates');
    });

    Route::middleware('can:rates.manage')->group(function (): void {
        Route::post('/rates', [BillingConfigurationController::class, 'storeRate'])->name('api.rates.store');
        Route::patch('/rates/{rate}', [BillingConfigurationController::class, 'updateRate'])
            ->name('api.rates.update');
    });

    // --- payments ------------------------------------------------------
    Route::middleware('can:payments.view')->group(function (): void {
        Route::get('/payments', [PaymentController::class, 'index'])->name('api.payments.index');
        Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('api.payments.show');

        // The payments domain's own vocabulary. The payments page used to read it from
        // `/api/receivables/vocabulary`, which made recording a payment depend on a
        // receivables permission an operator had no reason to hold. See `vocabulary()`.
        Route::get('/payments/vocabulary', [PaymentController::class, 'vocabulary'])
            ->name('api.payments.vocabulary');
    });

    Route::middleware('can:payments.create')->group(function (): void {
        Route::post('/payments', [PaymentController::class, 'store'])->name('api.payments.store');
    });

    Route::middleware('can:payments.allocate')->group(function (): void {
        Route::post('/payments/{payment}/allocations', [PaymentController::class, 'allocate'])
            ->name('api.payments.allocations.store');
        // The preview exists so an operator reconciling a backlog can see the plan
        // before committing to it. It writes nothing.
        Route::post('/payments/{payment}/auto-allocate/preview', [PaymentController::class, 'previewAutoAllocation'])
            ->name('api.payments.auto-allocate.preview');
        Route::post('/payments/{payment}/auto-allocate', [PaymentController::class, 'autoAllocate'])
            ->name('api.payments.auto-allocate');

        // The debts this client could be applied to. Manual allocation used to load
        // `clientAccount` through `receivables.view`, so `payments.allocate` silently
        // depended on an unrelated permission. See `allocatable()`.
        Route::get('/payments/clients/{client}/allocatable', [PaymentController::class, 'allocatable'])
            ->name('api.payments.allocatable');
        Route::post('/payment-allocations/{allocation}/reverse', [PaymentController::class, 'reverseAllocation'])
            ->name('api.payment-allocations.reverse');
    });

    Route::middleware('can:payments.void')->group(function (): void {
        Route::post('/payments/{payment}/void', [PaymentController::class, 'void'])
            ->name('api.payments.void');
    });

    // --- receivables ---------------------------------------------------
    Route::middleware('can:receivables.view')->group(function (): void {
        Route::get('/receivables', [ReceivableController::class, 'index'])->name('api.receivables.index');
        Route::get('/receivables/vocabulary', [ReceivableController::class, 'vocabulary'])
            ->name('api.receivables.vocabulary');
        Route::get('/clients/{client}/account', [ReceivableController::class, 'clientAccount'])
            ->name('api.clients.account');
    });

    // --- imports (A04) --------------------------------------------------
    //
    // Four permissions, kept in separate groups on purpose: `imports.review` can resolve
    // issues and rebuild the plan, `imports.apply` can write masters. §14 says the backend is
    // what decides, and every one of these sits inside a `can:` gate.
    //
    // There is no read-only variant of this module. Even `index` exposes the people in a
    // workbook, so `imports.view` is already the sensitive permission and §14 grants nothing
    // at all to Collections, Support or Read Only.

    Route::middleware('can:imports.view')->group(function (): void {
        Route::get('/imports', [ImportController::class, 'index'])->name('api.imports.index');
        Route::get('/imports/{import}', [ImportController::class, 'show'])->name('api.imports.show');
        Route::get('/imports/{import}/rows', [ImportController::class, 'rows'])->name('api.imports.rows');
        Route::get('/imports/{import}/issues', [ImportController::class, 'issues'])->name('api.imports.issues');
        Route::get('/imports/{import}/plan', [ImportController::class, 'plan'])->name('api.imports.plan');
    });

    Route::middleware('can:imports.create')->group(function (): void {
        Route::post('/imports', [ImportController::class, 'store'])->name('api.imports.store');
    });

    Route::middleware('can:imports.review')->group(function (): void {
        Route::put('/imports/{import}/interpretation-policy', [ImportController::class, 'interpretationPolicy'])
            ->name('api.imports.interpretation-policy');
        Route::post('/imports/{import}/issues/{issue}/resolve', [ImportController::class, 'resolveIssue'])
            ->name('api.imports.issues.resolve');
        Route::post('/imports/{import}/issues/bulk-resolve', [ImportController::class, 'bulkResolve'])
            ->name('api.imports.issues.bulk-resolve');
        Route::post('/imports/{import}/rebuild-plan', [ImportController::class, 'rebuildPlan'])
            ->name('api.imports.rebuild-plan');
        Route::post('/imports/{import}/cancel', [ImportController::class, 'cancel'])
            ->name('api.imports.cancel');
    });

    // §12.2: `ApplyImportPlan` locks the import row and accepts one `ready -> applying`
    // transition, so a second request gets a 409 from the action even if it reaches this
    // route a moment later.
    Route::middleware('can:imports.apply')->group(function (): void {
        Route::post('/imports/{import}/apply', [ImportController::class, 'apply'])->name('api.imports.apply');
    });

    // =================================================================
    // A05: planillas
    // =================================================================

    Route::middleware('can:planillas.view')->group(function (): void {
        Route::get('/planillas', [PlanillaController::class, 'index'])->name('api.planillas.index');
        Route::get('/planillas/vocabulary', [PlanillaController::class, 'vocabulary'])->name('api.planillas.vocabulary');
        Route::get('/planillas/{planilla}', [PlanillaController::class, 'show'])->name('api.planillas.show');
        Route::get('/planillas/{planilla}/export/xlsx', [PlanillaController::class, 'exportXlsx'])->name('api.planillas.export.xlsx');
        Route::get('/planillas/{planilla}/export/pdf', [PlanillaController::class, 'exportPdf'])->name('api.planillas.export.pdf');
        Route::get('/planillas/{planilla}/files/{file}', [PlanillaController::class, 'downloadFile'])->name('api.planillas.files.download');
    });

    Route::middleware('can:planillas.create')->group(function (): void {
        Route::post('/planillas/preview', [PlanillaController::class, 'preview'])->name('api.planillas.preview');
        Route::post('/planillas', [PlanillaController::class, 'store'])->name('api.planillas.store');
    });

    Route::middleware('can:planillas.validate')->group(function (): void {
        Route::post('/planillas/{planilla}/validate', [PlanillaController::class, 'validate'])->name('api.planillas.validate');
    });

    Route::middleware('can:planillas.submit')->group(function (): void {
        Route::post('/planillas/{planilla}/submit', [PlanillaController::class, 'submit'])->name('api.planillas.submit');
    });

    Route::middleware('can:planillas.mark_paid')->group(function (): void {
        Route::post('/planillas/{planilla}/mark-paid', [PlanillaController::class, 'markPaid'])->name('api.planillas.mark-paid');
    });

    Route::middleware('can:planillas.cancel')->group(function (): void {
        Route::post('/planillas/{planilla}/cancel', [PlanillaController::class, 'cancel'])->name('api.planillas.cancel');
    });

    Route::middleware('can:planillas.update')->group(function (): void {
        Route::post('/planillas/{planilla}/return-to-draft', [PlanillaController::class, 'returnToDraft'])->name('api.planillas.return-to-draft');
        Route::post('/planillas/{planilla}/files', [PlanillaController::class, 'uploadFile'])->name('api.planillas.files.store');

        // §23: the operational values of a draft. Without these a generated line could
        // never receive the amount validation asks for, and a real planilla could not
        // reach `ready` at all.
        Route::patch('/planillas/{planilla}', [PlanillaController::class, 'update'])->name('api.planillas.update');
        Route::patch('/planillas/{planilla}/lines/{line}', [PlanillaController::class, 'updateLine'])->name('api.planillas.lines.update');
    });

    // =================================================================
    // A05: novelties, tasks, calendar
    // =================================================================

    Route::middleware('can:novelties.view')->group(function (): void {
        Route::get('/novelties', [NoveltyController::class, 'index'])->name('api.novelties.index');
        Route::get('/novelties/vocabulary', [NoveltyController::class, 'vocabulary'])->name('api.novelties.vocabulary');
    });

    Route::middleware('can:novelties.manage')->group(function (): void {
        Route::post('/novelties', [NoveltyController::class, 'store'])->name('api.novelties.store');
        Route::post('/novelties/{novelty}/resolve', [NoveltyController::class, 'resolve'])->name('api.novelties.resolve');
        Route::post('/novelties/{novelty}/cancel', [NoveltyController::class, 'cancel'])->name('api.novelties.cancel');
    });

    Route::middleware('can:tasks.view')->group(function (): void {
        Route::get('/tasks', [TaskController::class, 'index'])->name('api.tasks.index');
        Route::get('/tasks/vocabulary', [TaskController::class, 'vocabulary'])->name('api.tasks.vocabulary');
    });

    Route::middleware('can:tasks.manage')->group(function (): void {
        Route::post('/tasks', [TaskController::class, 'store'])->name('api.tasks.store');
        Route::post('/tasks/{task}/complete', [TaskController::class, 'complete'])->name('api.tasks.complete');
        Route::post('/tasks/{task}/cancel', [TaskController::class, 'cancel'])->name('api.tasks.cancel');
        Route::post('/tasks/{task}/reassign', [TaskController::class, 'reassign'])->name('api.tasks.reassign');
    });

    Route::middleware('can:tasks.view')->group(function (): void {
        Route::get('/calendar', [CalendarController::class, 'index'])->name('api.calendar.index');
    });

    // =================================================================
    // A05: documents
    // =================================================================

    Route::middleware('can:documents.view')->group(function (): void {
        Route::get('/document-types', [DocumentController::class, 'indexTypes'])->name('api.document-types.index');
        Route::get('/document-requests', [DocumentController::class, 'indexRequests'])->name('api.document-requests.index');
        Route::get('/documents', [DocumentController::class, 'indexDocuments'])->name('api.documents.index');
        Route::get('/documents/{document}/download', [DocumentController::class, 'downloadDocument'])->name('api.documents.download');
    });

    Route::middleware('can:documents.manage')->group(function (): void {
        Route::post('/document-types', [DocumentController::class, 'storeType'])->name('api.document-types.store');
    });

    Route::middleware('can:documents.request')->group(function (): void {
        Route::post('/document-requests', [DocumentController::class, 'storeRequest'])->name('api.document-requests.store');
    });

    Route::middleware('can:documents.review')->group(function (): void {
        Route::post('/document-requests/{documentRequest}/receive', [DocumentController::class, 'markReceived'])->name('api.document-requests.receive');
        Route::post('/document-requests/{documentRequest}/review', [DocumentController::class, 'reviewRequest'])->name('api.document-requests.review');
        Route::post('/document-requests/{documentRequest}/cancel', [DocumentController::class, 'cancelRequest'])->name('api.document-requests.cancel');
    });

    Route::middleware('can:documents.manage')->group(function (): void {
        Route::post('/documents', [DocumentController::class, 'uploadDocument'])->name('api.documents.store');
    });

    // =================================================================
    // A05: staff review of a client's proposed profile change
    // =================================================================
    //
    // §44: the client proposes, staff decide. These two permissions existed from A05 with
    // nothing consuming them, which made the whole flow a one-way street.

    Route::middleware('can:client_update_requests.view')->group(function (): void {
        Route::get('/client-profile-update-requests', [ClientProfileUpdateRequestController::class, 'index'])
            ->name('api.client-update-requests.index');
    });

    Route::middleware('can:client_update_requests.review')->group(function (): void {
        Route::post('/client-profile-update-requests/{updateRequest}/approve', [ClientProfileUpdateRequestController::class, 'approve'])
            ->name('api.client-update-requests.approve');
        Route::post('/client-profile-update-requests/{updateRequest}/reject', [ClientProfileUpdateRequestController::class, 'reject'])
            ->name('api.client-update-requests.reject');
    });

    // =================================================================
    // A05: client portal
    // =================================================================

    Route::middleware('auth', 'auth.session', 'user.active')->group(function (): void {
        Route::get('/portal/home', [PortalProfileController::class, 'home'])->name('api.portal.home');
        Route::get('/portal/profile', [PortalProfileController::class, 'show'])->name('api.portal.profile');
        Route::post('/portal/profile/update-request', [PortalProfileController::class, 'submitUpdateRequest'])->name('api.portal.profile.update-request');
        Route::get('/portal/profile/update-requests', [PortalProfileController::class, 'updateRequests'])->name('api.portal.profile.update-requests');
        Route::get('/portal/financial-account', [PortalProfileController::class, 'financialAccount'])->name('api.portal.financial-account');
        Route::get('/portal/relationships', [PortalRelationshipController::class, 'index'])->name('api.portal.relationships');
        Route::get('/portal/documents', [PortalDocumentController::class, 'index'])->name('api.portal.documents');
        Route::get('/portal/documents/{document}/download', [PortalDocumentController::class, 'download'])->name('api.portal.documents.download');
        Route::get('/portal/document-requests', [PortalDocumentController::class, 'indexRequests'])->name('api.portal.document-requests');
        Route::post('/portal/document-requests/{documentRequest}/upload', [PortalDocumentController::class, 'uploadResponse'])->name('api.portal.document-requests.upload');
    });

    // =================================================================
    // A05: portal account management (staff)
    // =================================================================

    Route::middleware('can:portal_accounts.manage')->group(function (): void {
        Route::post('/clients/{client}/portal-account', [ClientController::class, 'createPortalAccount'])->name('api.clients.portal-account.store');
        Route::post('/users/{user}/portal-account/deactivate', [ClientController::class, 'deactivatePortalAccount'])->name('api.users.portal-account.deactivate');
    });

    // =================================================================
    // A05: reports
    // =================================================================

    Route::middleware('can:reports.view')->group(function (): void {
        Route::get('/reports/vocabulary', [ReportController::class, 'vocabulary'])->name('api.reports.vocabulary');
        Route::get('/reports', [ReportController::class, 'index'])->name('api.reports.index');
        Route::get('/reports/generated', [ReportController::class, 'indexGenerated'])->name('api.reports.generated');
    });

    Route::middleware('can:reports.export')->group(function (): void {
        Route::get('/reports/download', [ReportController::class, 'download'])->name('api.reports.download');
        Route::get('/reports/generated/{generatedReport}/download', [ReportController::class, 'downloadGenerated'])->name('api.reports.generated.download');
    });

    // §59: the notification inbox. Not permission-gated: a notification is addressed to
    // one account, and ownership is the authorisation. A client account has none of the
    // permissions above, so it cannot reach the staff sections these routes sit beside.
    Route::middleware('auth')->group(function (): void {
        Route::get('/notifications', [NotificationController::class, 'index'])->name('api.notifications.index');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('api.notifications.read-all');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('api.notifications.read');
    });

    Route::middleware('can:reports.schedule')->group(function (): void {
        Route::get('/report-schedules', [ReportController::class, 'indexSchedules'])->name('api.report-schedules.index');
        Route::post('/report-schedules', [ReportController::class, 'storeSchedule'])->name('api.report-schedules.store');
        Route::post('/report-schedules/{reportSchedule}/deactivate', [ReportController::class, 'deactivateSchedule'])->name('api.report-schedules.deactivate');
        Route::post('/report-schedules/run-due', [ReportController::class, 'runDueSchedules'])->name('api.report-schedules.run-due');
    });

    // =================================================================
    // A06: Support Center
    // =================================================================

    Route::middleware('can:support.view')->group(function (): void {
        Route::get('/support/inbox', [SupportInboxController::class, 'index'])
            ->name('api.support.inbox');
        Route::get('/support/conversations/{conversation}', [SupportConversationController::class, 'show'])
            ->name('api.support.conversations.show');

        /*
         * Reading an attachment needs `support.view`, not `support.reply`.
         *
         * It was declared inside the reply group, so downloading a file required
         * authority to write into the conversation. A role that could read a
         * conversation but not post in it — a supervisor triaging, say — could
         * read the thread and then be refused the documents attached to it.
         * The per-conversation check in the controller still applies, so this
         * widens who may *attempt* the read, not who may succeed.
         */
        Route::get('/support/conversations/{conversation}/attachments/{attachment}', [SupportConversationController::class, 'downloadAttachment'])
            ->name('api.support.conversations.attachments.download');
        Route::get('/support/queues', [SupportQueueController::class, 'index'])
            ->name('api.support.queues.index');
    });

    Route::middleware('can:support.reply')->group(function (): void {
        Route::post('/support/conversations/{conversation}/messages', [SupportConversationController::class, 'sendMessage'])
            ->name('api.support.conversations.messages.store');
        Route::post('/support/conversations/{conversation}/notes', [SupportConversationController::class, 'addNote'])
            ->name('api.support.conversations.notes.store');
        Route::post('/support/conversations/{conversation}/read', [SupportConversationController::class, 'markRead'])
            ->name('api.support.conversations.read');
    });

    Route::middleware('can:support.assign')->group(function (): void {
        Route::post('/support/conversations/{conversation}/assign', [SupportConversationController::class, 'assign'])
            ->name('api.support.conversations.assign');
        Route::post('/support/conversations/{conversation}/queue', [SupportConversationController::class, 'changeQueue'])
            ->name('api.support.conversations.queue');
        Route::post('/support/conversations/{conversation}/priority', [SupportConversationController::class, 'changePriority'])
            ->name('api.support.conversations.priority');
    });

    Route::middleware('can:support.resolve')->group(function (): void {
        Route::post('/support/conversations/{conversation}/resolve', [SupportConversationController::class, 'resolve'])
            ->name('api.support.conversations.resolve');
        Route::post('/support/conversations/{conversation}/close', [SupportConversationController::class, 'close'])
            ->name('api.support.conversations.close');
        Route::post('/support/conversations/{conversation}/reopen', [SupportConversationController::class, 'reopen'])
            ->name('api.support.conversations.reopen');
    });

    Route::middleware('can:support.manage_queues')->group(function (): void {
        Route::post('/support/queues', [SupportQueueController::class, 'store'])
            ->name('api.support.queues.store');
        Route::patch('/support/queues/{queue}', [SupportQueueController::class, 'update'])
            ->name('api.support.queues.update');
        Route::post('/support/queues/{queue}/members', [SupportQueueController::class, 'addMember'])
            ->name('api.support.queues.members.store');
        Route::delete('/support/queues/{queue}/members/{user}', [SupportQueueController::class, 'removeMember'])
            ->name('api.support.queues.members.destroy');
    });

    Route::middleware('can:support.manage_sla')->group(function (): void {
        // SLA configuration is part of queue management
    });

    Route::middleware('can:support.review_unlinked_email')->group(function (): void {
        Route::get('/support/unlinked-email', [SupportInboundEmailController::class, 'index'])
            ->name('api.support.unlinked-email.index');
        Route::post('/support/unlinked-email/{email}/link', [SupportInboundEmailController::class, 'link'])
            ->name('api.support.unlinked-email.link');
        Route::post('/support/unlinked-email/{email}/discard', [SupportInboundEmailController::class, 'discard'])
            ->name('api.support.unlinked-email.discard');
    });

    // =================================================================
    // A06: Client Portal Support
    // =================================================================

    Route::middleware(['auth', 'auth.session', 'user.active'])->group(function (): void {
        Route::get('/portal/support/conversations', [App\Http\Controllers\Api\Portal\SupportConversationController::class, 'index'])
            ->name('api.portal.support.conversations.index');
        Route::post('/portal/support/conversations', [App\Http\Controllers\Api\Portal\SupportConversationController::class, 'store'])
            ->name('api.portal.support.conversations.store');
        Route::get('/portal/support/conversations/{conversation}', [App\Http\Controllers\Api\Portal\SupportConversationController::class, 'show'])
            ->name('api.portal.support.conversations.show');
        Route::post('/portal/support/conversations/{conversation}/messages', [App\Http\Controllers\Api\Portal\SupportConversationController::class, 'sendMessage'])
            ->name('api.portal.support.conversations.messages.store');
        Route::post('/portal/support/conversations/{conversation}/attachments', [App\Http\Controllers\Api\Portal\SupportConversationController::class, 'uploadAttachment'])
            ->name('api.portal.support.conversations.attachments.store');
        Route::get('/portal/support/conversations/{conversation}/attachments/{attachment}', [App\Http\Controllers\Api\Portal\SupportConversationController::class, 'downloadAttachment'])
            ->name('api.portal.support.conversations.attachments.download');
        Route::post('/portal/support/conversations/{conversation}/read', [App\Http\Controllers\Api\Portal\SupportConversationController::class, 'markRead'])
            ->name('api.portal.support.conversations.read');
    });

    // =================================================================
    // A06: Push Notifications
    // =================================================================

    Route::middleware(['auth', 'auth.session', 'user.active'])->group(function (): void {
        Route::get('/push/vapid-public-key', [PushController::class, 'vapidPublicKey'])
            ->name('api.push.vapid-public-key');
        Route::post('/push/subscriptions', [PushController::class, 'store'])
            ->name('api.push.subscriptions.store');
        Route::delete('/push/subscriptions/{subscription}', [PushController::class, 'destroy'])
            ->name('api.push.subscriptions.destroy');
    });

    // =================================================================
    // A06: Telegram Admin
    // =================================================================

    Route::middleware('can:notification_channels.manage')->group(function (): void {
        Route::get('/telegram/endpoints', [TelegramController::class, 'index'])
            ->name('api.telegram.endpoints.index');
        Route::post('/telegram/endpoints', [TelegramController::class, 'store'])
            ->name('api.telegram.endpoints.store');
        Route::patch('/telegram/endpoints/{endpoint}', [TelegramController::class, 'update'])
            ->name('api.telegram.endpoints.update');
        Route::post('/telegram/endpoints/{endpoint}/test', [TelegramController::class, 'test'])
            ->name('api.telegram.endpoints.test');
    });

    // =================================================================
    // A06: Automations
    // =================================================================

    Route::middleware('can:automations.view')->group(function (): void {
        Route::get('/automations/vocabulary', [AutomationController::class, 'vocabulary'])
            ->name('api.automations.vocabulary');
        Route::get('/automations', [AutomationController::class, 'index'])
            ->name('api.automations.index');
        Route::get('/automations/{rule}', [AutomationController::class, 'show'])
            ->name('api.automations.show');
        Route::get('/automations/{rule}/runs', [AutomationController::class, 'runs'])
            ->name('api.automations.runs');
    });

    Route::middleware('can:automations.manage')->group(function (): void {
        Route::post('/automations', [AutomationController::class, 'store'])
            ->name('api.automations.store');
        Route::patch('/automations/{rule}', [AutomationController::class, 'update'])
            ->name('api.automations.update');
        Route::post('/automations/{rule}/activate', [AutomationController::class, 'activate'])
            ->name('api.automations.activate');
        Route::post('/automations/{rule}/deactivate', [AutomationController::class, 'deactivate'])
            ->name('api.automations.deactivate');
        Route::post('/automations/validate-preview', [AutomationController::class, 'validatePreview'])
            ->name('api.automations.validate-preview');
    });

    // Presence heartbeat (no permission, just auth)
    Route::middleware(['auth', 'auth.session', 'user.active'])->group(function (): void {
        Route::post('/support/presence/heartbeat', [SupportPresenceController::class, 'heartbeat'])
            ->name('api.support.presence.heartbeat');
        Route::post('/portal/support/presence/heartbeat', [App\Http\Controllers\Api\Portal\SupportPresenceController::class, 'heartbeat'])
            ->name('api.portal.support.presence.heartbeat');
    });
});
