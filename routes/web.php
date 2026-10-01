<?php

declare(strict_types=1);

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Document routes
|--------------------------------------------------------------------------
|
| The interface is a single page application. Every known document route
| returns the same shell; the Vue router then renders the right screen, which
| keeps deep links, refreshes and the 404 screen working.
|
| The JSON API lives under /api (routes/api.php) and is therefore excluded
| from the catch-all below, so an unknown API path still produces a JSON 404
| instead of the HTML shell.
|
*/

Route::view('/', 'app')->name('home');

Route::get('/dashboard', SpaController::class)->name('dashboard');
Route::get('/profile', SpaController::class)->name('profile');
Route::get('/settings', SpaController::class)->name('settings');

Route::get('/login', SpaController::class)->name('login');
Route::get('/forgot-password', SpaController::class)->name('password.request');

// Named `password.reset` because the framework's reset notification builds the
// recovery link from this route name.
Route::get('/reset-password/{token}', SpaController::class)
    ->where('token', '[A-Za-z0-9]+')
    ->name('password.reset');

// Unknown document path: hand it to the interface, which shows the 404 screen.
Route::fallback(SpaController::class)
    ->where('fallbackPlaceholder', '^(?!api|sanctum|up)(?!storage)(?!build)(?!hot)(?!favicon\.ico).*$');
