<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

/*
| Lo que queda del andamiaje de autenticación del starter kit.
|
| `T-012` sustituyó el acceso y la recuperación de contraseña por los del
| backoffice (`routes/backoffice.php`, `REQ-BOF-001`), y retiró el resto:
|
|  - `login`, `logout`, `forgot-password` y `reset-password` → sustituidos;
|  - `confirm-password` → borrado. Era el andamiaje del middleware
|    `password.confirm` de Laravel, que este producto no usa en ninguna
|    operación.
|
| La verificación de correo **se conserva**: `REQ-ORG-001` RB-5 la exige antes
| de publicar un catálogo, y el resto del backoffice funciona sin verificar
| para no bloquear la exploración del producto. Quien la enruta de verdad —y
| quien la hace obligatoria— es la tarea de publicación de catálogos; aquí
| sigue en pie el flujo que ya funcionaba en vez de borrarlo para reescribirlo
| igual dentro de dos hitos.
|
| Declara superficie `outside-tenancy` como todo lo que ocurre alrededor de la
| identidad y no dentro de un negocio (`REQ-QAX-008` SP-1).
*/

Route::middleware(['auth', 'outside-tenancy'])->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});
