<?php

declare(strict_types=1);

use App\Http\Controllers\Portal\AccessController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\LayawayShowController;
use App\Http\Controllers\Portal\LoginLinkController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Portal del cliente — `/mi-cuenta` ([05 §7.3])
|--------------------------------------------------------------------------
|
| Antes que `catalog.php`, cuyo comodín `/{organization:slug}` se tragaría
| cualquier ruta que llegara después (`mi-cuenta` además es un slug reservado).
|
| El acceso no tiene tenant: el negocio sale del código del apartado, dentro de
| `StartScopedSessionAction`, y el límite de intentos lo pone su `FormRequest`
| (`REQ-IDN-008` RB-5). Lo que lee datos del negocio va detrás de la sesión
| (`portal`) y con el tenant de la ficha (`portal-tenant`, `REQ-IDN-004` RB-2).
| Salir no lee nada del negocio: sesión, sin tenant.
|
| Ninguna pregunta por la suspensión del negocio: el portal del cliente no se
| interrumpe por el impago de la plataforma (`REQ-SUB-007`, `REQ-IDN-008`
| RB-8).
*/
Route::prefix('mi-cuenta')->name('portal.')->group(function (): void {
    Route::middleware('outside-tenancy')->group(function (): void {
        Route::get('acceso', [AccessController::class, 'create'])->name('access');
        Route::post('acceso/codigo', [AccessController::class, 'storeCode'])->name('access.code');

        // `REQ-IDN-003` · el enlace por correo. El `GET` del enlace solo
        // confirma; el canje es el `POST` (RB-9). El tenant del canje lo dice
        // el prefijo del token, dentro de la acción.
        Route::post('acceso/enlace', [LoginLinkController::class, 'store'])->name('link.store');
        Route::get('acceso/enlace/{token}', [LoginLinkController::class, 'show'])->name('link.show');
        Route::post('acceso/enlace/{token}', [LoginLinkController::class, 'consume'])->name('link.consume');
    });

    Route::middleware(['portal', 'outside-tenancy'])
        ->post('salir', [AccessController::class, 'destroy'])
        ->name('logout');

    Route::middleware(['portal', 'portal-tenant'])->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('apartados/{code}', LayawayShowController::class)->name('layaways.show');
    });
});
