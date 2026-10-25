<?php

declare(strict_types=1);

use App\Http\Controllers\Catalog\CatalogShowController;
use App\Http\Controllers\Catalog\CatalogSubscriptionController;
use App\Http\Controllers\Catalog\ChatController as CatalogChatController;
use App\Http\Controllers\Catalog\LayawayController;
use App\Http\Controllers\Catalog\OrganizationStorefrontController;
use App\Http\Controllers\Catalog\ProductShowController;
use App\Support\CatalogShareLink;
use App\Support\ReservedSlugs;
use Illuminate\Support\Facades\Route;

Route::middleware('catalog-tenant')
    ->prefix(CatalogShareLink::PREFIX)
    ->group(function (): void {
        Route::get('{catalog:slug}', CatalogShowController::class)->name(CatalogShareLink::ROUTE);

        Route::get('{catalog:slug}/p/{product:slug}', ProductShowController::class)->name(CatalogShareLink::PRODUCT_ROUTE);

        Route::get('{catalog:slug}/apartar/{product:slug}', [LayawayController::class, 'create'])->name('catalog.layaway.create');
        Route::post('{catalog:slug}/apartar/{product:slug}', [LayawayController::class, 'store'])
            ->withoutScopedBindings()
            ->middleware('throttle:apartados')
            ->name('catalog.layaway.store');
        Route::get('{catalog:slug}/apartar/{code}/gracias', [LayawayController::class, 'thanks'])->name('catalog.layaway.thanks');

        Route::post('{catalog:slug}/seguir', [CatalogSubscriptionController::class, 'store'])->name('catalog.follow');
        Route::delete('{catalog:slug}/seguir', [CatalogSubscriptionController::class, 'destroy'])->name('catalog.unfollow');

        Route::post('{catalog:slug}/chat', [CatalogChatController::class, 'store'])->middleware('throttle:mensajes')->name('catalog.chat');
    });

Route::middleware('storefront-tenant')
    ->get('{organization:slug}', OrganizationStorefrontController::class)
    ->where('organization', ReservedSlugs::routePattern())
    ->name(CatalogShareLink::STOREFRONT_ROUTE);
