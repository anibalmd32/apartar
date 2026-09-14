<?php

declare(strict_types=1);

use App\Http\Controllers\Marketing\RegisterController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
| Cada ruta declara su superficie de tenancy (REQ-ORG-002 RB-4), y la suite de
| REQ-QAX-008 falla con cualquiera que no declare ninguna. La portada es
| marketing y vive fuera del modelo; el escritorio es la primera superficie de
| backoffice y resuelve su organización desde la sesión.
*/

Route::get('/', function () {
    return Inertia::render('welcome');
})->middleware('outside-tenancy')->name('home');

/*
| Registro (REQ-ORG-001). Fuera del modelo de tenancy, y no por comodidad: la
| organización **no existe todavía** cuando estas dos rutas se ejecutan, así
| que no hay tenant que resolver. El de la nueva se fija dentro de la acción,
| una vez creada.
|
| `guest` porque quien ya entró no se registra: `users.email` es único en toda
| la plataforma (RB-3), así que no podría abrir un segundo negocio con su mismo
| correo aunque lo intentara.
|
| Rate limit de 5 por hora y por IP (RB-8): el registro es abierto, sin
| invitación ni lista de espera, y esto es lo único que lo separa de un grifo.
*/
Route::middleware(['guest', 'outside-tenancy'])->group(function () {
    Route::get('registro', [RegisterController::class, 'create'])->name('register');

    Route::post('registro', [RegisterController::class, 'store'])
        ->middleware('throttle:registro');
});

require __DIR__.'/backoffice.php';
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
