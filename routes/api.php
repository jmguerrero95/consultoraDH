<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SettingsController;
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
| Authorisation is enforced on the server with the `auth` middleware and, for
| the settings endpoint, with a permission check. The interface hides links the
| user cannot open, but that is presentation only: the server never relies on it.
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
});
