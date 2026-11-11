<?php

declare(strict_types=1);

use App\Http\Controllers\Monitor\AccessController;
use App\Http\Middleware\AssignVisitorId;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

$domain = config('monitor.domain');
$onDomain = is_string($domain) && $domain !== '';

$routes = function () use ($onDomain): void {
    $silent = [ValidateCsrfToken::class, HandleInertiaRequests::class, AddLinkHeadersForPreloadedAssets::class, AssignVisitorId::class];

    Route::middleware('outside-tenancy')->prefix($onDomain ? '' : 'monitor')->name('monitor.')->group(function () use ($silent): void {
        Route::get('/', [AccessController::class, 'create'])->withoutMiddleware($silent)->name('access');
        Route::post('/', [AccessController::class, 'store'])->withoutMiddleware($silent)->middleware('throttle:20,1')->name('access.store');
        Route::post('salir', [AccessController::class, 'destroy'])->name('logout');
    });
};

$onDomain ? Route::domain((string) $domain)->group($routes) : $routes();
