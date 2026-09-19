<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ExchangeRateController;
use Illuminate\Support\Facades\Route;

/*
| Endpoints internos que consume el propio frontend (REQ-FX-002 RB-5). No es
| una API pública de terceros: no hay tokens ni versionado, y lo que vive aquí
| es lo que una pestaña abierta necesita refrescar sin recargar la página.
|
| `outside-tenancy` declara que la ruta no pertenece a ninguna superficie de
| REQ-ORG-002 RB-4, y aquí no es una formalidad: la tasa de una fuente pública
| es la misma para toda la plataforma, así que resolver un tenant sería fingir
| que la respuesta depende de quién pregunta. Es además lo que hace cierta la
| regla de RB-6 —el endpoint no revela qué organización usa qué fuente— por
| construcción y no por cuidado de quien lo escribió.
*/

Route::middleware('outside-tenancy')->group(function (): void {
    Route::get('tasa', ExchangeRateController::class)->name('api.rate');
});
