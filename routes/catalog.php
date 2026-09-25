<?php

declare(strict_types=1);

use App\Http\Controllers\Catalog\CatalogShowController;
use App\Http\Controllers\Catalog\OrganizationStorefrontController;
use App\Http\Controllers\Catalog\ProductShowController;
use App\Support\CatalogShareLink;
use App\Support\ReservedSlugs;
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
| La ficha entra con `T-035`; las que faltan —apartado, seguir, chat— entran
| con su tarea. La vitrina `/{organization:slug}` va **al final** del archivo,
| con su propio bloque: es un comodín de un segmento.
*/

Route::middleware('catalog-tenant')
    ->prefix(CatalogShareLink::PREFIX)
    ->group(function (): void {
        Route::get('{catalog:slug}', CatalogShowController::class)->name(CatalogShareLink::ROUTE);

        // La ficha solo existe dentro de un catálogo (REQ-CAT-003 RB-1): el
        // producto lo resuelve `Catalog::resolveChildRouteBinding()`, que mira
        // antes el catálogo (SP-3) y después la pertenencia y el estado.
        Route::get('{catalog:slug}/p/{product:slug}', ProductShowController::class)->name(CatalogShareLink::PRODUCT_ROUTE);
    });

/*
| La vitrina del negocio, `apartar.store/{slug}` (REQ-ORG-006 RB-6, `T-036`).
|
| Un comodín de un segmento en la raíz del dominio compite con toda ruta
| literal de un segmento, incluidas las que todavía no existen. Dos cosas lo
| sostienen, y la segunda es la que no depende de acordarse de nada:
|
|  - va **al final** de este archivo, que `routes/web.php` carga el último;
|  - su parámetro **no admite** ningún slug de `ReservedSlugs`: `/precios`,
|    `/terminos` o `/c` no pueden llegar aquí aunque su ruta se registre
|    después, o aunque no exista todavía.
|
| `StorefrontRoutingTest` recorre los segmentos reservados y las rutas
| literales de un segmento y afirma que ninguno resuelve a la vitrina.
*/

Route::middleware('storefront-tenant')
    ->get('{organization:slug}', OrganizationStorefrontController::class)
    ->where('organization', ReservedSlugs::routePattern())
    ->name(CatalogShareLink::STOREFRONT_ROUTE);
