<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingConfigurationController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PeriodController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReceivableController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SocialSecurityEntityController;
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
});
