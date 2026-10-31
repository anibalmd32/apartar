<?php

declare(strict_types=1);

use App\Http\Controllers\Monitor\AccessController;
use Illuminate\Support\Facades\Route;

$domain = config('monitor.domain');

$routes = function () use ($domain): void {
    Route::middleware('outside-tenancy')->prefix('monitor')->name('monitor.')->group(function (): void {
        Route::get('acceso', [AccessController::class, 'create'])->name('access');
        Route::post('acceso', [AccessController::class, 'store'])->middleware('throttle:20,1')->name('access.store');
        Route::post('salir', [AccessController::class, 'destroy'])->name('logout');
    });

    if (is_string($domain) && $domain !== '') {
        Route::middleware('outside-tenancy')->get('/', fn () => redirect()->to('/'.config('pulse.path', 'pulse')))->name('monitor.home');
    }
};

is_string($domain) && $domain !== '' ? Route::domain($domain)->group($routes) : $routes();
