<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SocialSecurityEntityController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

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
});
