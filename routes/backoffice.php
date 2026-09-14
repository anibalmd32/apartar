<?php

declare(strict_types=1);

use App\Http\Controllers\Backoffice\Auth\ForcedPasswordChangeController;
use App\Http\Controllers\Backoffice\Auth\LoginController;
use App\Http\Controllers\Backoffice\Auth\PasswordResetController;
use App\Http\Controllers\Backoffice\TeamController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
| Backoffice del negocio ([05 §7.4], `REQ-BOF-001`).
|
| Dos grupos con superficies distintas, y la distinción no es formal:
|
|  - **acceso** (`outside-tenancy`): login y recuperación de contraseña
|    ocurren **antes** de saber de qué organización es quien escribe. No hay
|    tenant que resolver, y por eso `users.email` es único en toda la
|    plataforma (RB-2).
|  - **dentro** (`tenant`): a partir del guard, la organización se deriva del
|    usuario y `ResolveTenant` la fija. Ningún controlador escribe un `where`.
|
| El orden de los middlewares del segundo grupo es el de la cadena de
| REQ-BOF-001: tenant → persona activa → organización activa → contraseña al
| día. Cada uno da por hecho lo que resolvió el anterior.
|
| RB-11: nada de esto se enlaza desde el catálogo ni desde el portal.
*/

Route::prefix('backoffice')->group(function (): void {
    Route::middleware(['guest', 'outside-tenancy'])->group(function (): void {
        Route::get('login', [LoginController::class, 'create'])->name('login');
        Route::post('login', [LoginController::class, 'store']);

        Route::get('recuperar', [PasswordResetController::class, 'create'])->name('password.request');
        Route::post('recuperar', [PasswordResetController::class, 'store'])->name('password.email');

        Route::get('restablecer/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
        Route::post('restablecer', [PasswordResetController::class, 'update'])->name('password.store');
    });

    Route::middleware(['auth', 'tenant', 'admin-active', 'organization-active', 'password-changed'])
        ->group(function (): void {
            /*
            | El panel sobrevive a la suspensión (RB-9): es donde se ve el
            | aviso y el enlace para regularizar. Bloquearlo dejaría al equipo
            | sin saber por qué no puede hacer nada.
            */
            Route::get('/', fn () => Inertia::render('backoffice/Dashboard'))
                ->middleware('during-suspension')
                ->name('dashboard');

            Route::post('logout', [LoginController::class, 'destroy'])
                ->middleware('during-suspension')
                ->name('logout');

            /*
            | El cambio obligatorio va dentro del guard y también sobrevive a
            | la suspensión: encerrar a alguien entre dos exigencias que se
            | bloquean mutuamente sería una trampa.
            */
            Route::get('cambiar-contrasena', [ForcedPasswordChangeController::class, 'edit'])
                ->middleware('during-suspension')
                ->name('password.change');

            Route::post('cambiar-contrasena', [ForcedPasswordChangeController::class, 'update'])
                ->middleware('during-suspension')
                ->name('password.change.store');

            /*
            | Equipo (`REQ-ORG-004`, `REQ-ORG-005`). Del `owner`, por [05 §7.4]
            | y por `REQ-ORG-003` RB-1: quién entra al negocio es gobierno, no
            | operación diaria.
            |
            | **Ninguna declara `during-suspension`, y es la respuesta
            | correcta.** Lo que sobrevive a una suspensión es cobrar y aprobar
            | comprobantes (`REQ-ORG-008` RB-3); ampliar el equipo mientras se
            | le debe dinero a la plataforma es justo lo contrario de eso.
            | Reactivar y revocar tampoco pasan, y aquí la omisión no es
            | descuido sino el lado seguro del fallo cerrado: cuando se
            | regularice el pago vuelven todas a la vez.
            |
            | El `{member}` se resuelve **ya filtrado** por el tenant, así que
            | el identificador de otra organización da `404` y no `403`
            | (`REQ-ORG-002` RB-6): no se confirma que exista.
            */
            Route::middleware('owner')->group(function (): void {
                Route::get('equipo', [TeamController::class, 'index'])->name('team.index');
                Route::post('equipo', [TeamController::class, 'store'])->name('team.store');

                Route::post('equipo/{member}/reenviar', [TeamController::class, 'resend'])->name('team.resend');
                Route::delete('equipo/{member}', [TeamController::class, 'destroy'])->name('team.destroy');

                Route::post('equipo/{member}/desactivar', [TeamController::class, 'disable'])->name('team.disable');
                Route::post('equipo/{member}/activar', [TeamController::class, 'enable'])->name('team.enable');
            });
        });
});
