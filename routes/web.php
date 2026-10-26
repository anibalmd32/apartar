<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\Marketing\HomeController;
use App\Http\Controllers\Marketing\LegalController;
use App\Http\Controllers\Marketing\PricingController;
use App\Http\Controllers\Marketing\RegisterController;
use Illuminate\Support\Facades\Route;

Route::middleware('outside-tenancy')->group(function (): void {
    Route::get('/', HomeController::class)->name('home');
    Route::get('precios', PricingController::class)->name('pricing');
    Route::get('terminos', [LegalController::class, 'terms'])->name('legal.terms');
    Route::get('privacidad', [LegalController::class, 'privacy'])->name('legal.privacy');
});

Route::middleware(['guest', 'outside-tenancy'])->group(function () {
    Route::get('registro', [RegisterController::class, 'create'])->name('register');

    Route::post('registro', [RegisterController::class, 'store'])
        ->middleware('throttle:registro');

    Route::get('registro/enlace', [RegisterController::class, 'slug'])
        ->middleware('throttle:registro-enlace')
        ->name('register.slug');
});

Route::middleware(['guest', 'outside-tenancy'])->group(function (): void {
    Route::get('invitacion/{token}', [AcceptInvitationController::class, 'show'])
        ->name('team.invitation.show');

    Route::post('invitacion/{token}', [AcceptInvitationController::class, 'store'])
        ->middleware('throttle:10,15')
        ->name('team.invitation.store');
});

require __DIR__.'/platform.php';
require __DIR__.'/backoffice.php';
require __DIR__.'/auth.php';
require __DIR__.'/portal.php';
require __DIR__.'/catalog.php';
