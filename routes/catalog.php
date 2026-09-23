<?php

declare(strict_types=1);

use App\Http\Controllers\Catalog\CatalogShowController;
use App\Support\CatalogShareLink;
use Illuminate\Support\Facades\Route;

/*
| Superficie pública del catálogo ([05 §7.2], REQ-CAT-021).
|
| **El tenant sale del catálogo de la URL** (REQ-ORG-002 RB-4): `catalog-tenant`
| lee el slug, fija la organización y solo entonces deja que el binding
| resuelva `{catalog:slug}`, ya con el global scope puesto. Es la declaración
| de superficie que exige la suite de REQ-QAX-008, y la única: estas rutas no
| llevan `auth` porque el catálogo se abre sin cuenta (RB-2).
|
| Sin `during-suspension`: esa marca es del backoffice. Una organización
| suspendida tiene sus catálogos despublicados (REQ-ORG-008 RB-2), así que
| aquí dan `404` por su propio estado y no por un middleware (SP-5).
|
| `AssignVisitorId`, que [05 §7.2] monta en este grupo, llega con la
| telemetría (`T-084`): hoy no hay nada que atribuir a ese identificador.
|
| Las que faltan —ficha, apartado, seguir, chat— entran con su tarea. Y
| `/{organization:slug}`, cuando llegue con `T-036`, va **al final** del
| archivo: es un comodín de un segmento y debe ceder ante toda ruta literal.
*/

Route::middleware('catalog-tenant')
    ->prefix(CatalogShareLink::PREFIX)
    ->group(function (): void {
        Route::get('{catalog:slug}', CatalogShowController::class)->name(CatalogShareLink::ROUTE);
    });
