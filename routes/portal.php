<?php

declare(strict_types=1);

use App\Http\Controllers\Catalog\CatalogSubscriptionController;
use App\Http\Controllers\PaymentFileController;
use App\Http\Controllers\Portal\AccessController;
use App\Http\Controllers\Portal\Auth\GoogleAccountController;
use App\Http\Controllers\Portal\ChatController;
use App\Http\Controllers\Portal\ClaimController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\FeedController;
use App\Http\Controllers\Portal\LayawayShowController;
use App\Http\Controllers\Portal\LoginLinkController;
use App\Http\Controllers\Portal\PayController;
use App\Http\Controllers\Portal\PushSubscriptionController;
use App\Http\Controllers\Portal\ReceiptController;
use App\Http\Controllers\Portal\SubscriptionsController;
use App\Http\Controllers\ReceiptFileController;
use Illuminate\Support\Facades\Route;

Route::middleware('outside-tenancy')->prefix('cuenta/google')->name('account.google.')->group(function (): void {
    Route::get('redirect', [GoogleAccountController::class, 'redirect'])->name('redirect');
    Route::get('callback', [GoogleAccountController::class, 'callback'])->name('callback');
});

Route::middleware('outside-tenancy')
    ->get('cuenta/seguir', [CatalogSubscriptionController::class, 'complete'])
    ->name('account.follow.complete');

Route::prefix('mi-cuenta')->name('portal.')->group(function (): void {
    Route::middleware('outside-tenancy')->group(function (): void {
        Route::get('acceso', [AccessController::class, 'create'])->name('access');
        Route::post('acceso/codigo', [AccessController::class, 'storeCode'])->name('access.code');

        Route::post('acceso/enlace', [LoginLinkController::class, 'store'])->name('link.store');
        Route::get('acceso/enlace/{token}', [LoginLinkController::class, 'show'])->name('link.show');
        Route::post('acceso/enlace/{token}', [LoginLinkController::class, 'consume'])->name('link.consume');
    });

    Route::middleware('outside-tenancy')
        ->post('salir', [AccessController::class, 'destroy'])
        ->name('logout');

    Route::middleware(['account-session', 'outside-tenancy'])->group(function (): void {
        Route::get('suscripciones', [SubscriptionsController::class, 'index'])->name('subscriptions');
        Route::get('novedades', FeedController::class)->name('feed');
        Route::patch('suscripciones/{catalog}', [SubscriptionsController::class, 'update'])
            ->whereNumber('catalog')
            ->name('subscriptions.update');

        Route::get('reclamar', [ClaimController::class, 'create'])->name('claim');
        Route::post('reclamar', [ClaimController::class, 'store'])->name('claim.store');
    });

    Route::middleware(['portal', 'outside-tenancy'])->group(function (): void {
        Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
        Route::get('chat/{organization:slug}', [ChatController::class, 'show'])->name('chat.show');
        Route::post('chat/{organization:slug}', [ChatController::class, 'store'])->middleware('throttle:mensajes')->name('chat.store');
        Route::get('chat/{organization:slug}/mensajes', [ChatController::class, 'messages'])->name('chat.messages');
        Route::post('chat/{organization:slug}/escribiendo', [ChatController::class, 'typing'])->middleware('throttle:60,1')->name('chat.typing');

        Route::post('push', [PushSubscriptionController::class, 'store'])->middleware('throttle:20,1')->name('push.store');
        Route::delete('push', [PushSubscriptionController::class, 'destroy'])->name('push.destroy');
    });

    Route::middleware(['portal', 'portal-tenant'])->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('pagar', PayController::class)->name('pay');
        Route::get('apartados/{code}', LayawayShowController::class)->name('layaways.show');

        Route::get('apartados/{code}/comprobante', [ReceiptController::class, 'create'])->name('receipts.create');
        Route::post('apartados/{code}/comprobante', [ReceiptController::class, 'store'])
            ->middleware('throttle:comprobantes')
            ->name('receipts.store');

        Route::get('comprobantes/{receipt}/archivo', ReceiptFileController::class)
            ->middleware('signed')
            ->name('receipts.file');

        Route::get('pagos/{payment}/archivo', PaymentFileController::class)
            ->whereNumber('payment')
            ->middleware('signed')
            ->name('payments.file');
    });
});
