<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\OperatesWithoutTenant;
use App\Http\Middleware\OutsideTenancy;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // El tenant se fija ANTES de que `SubstituteBindings` resuelva el
        // binding, y este orden es lo que hace verdadera la regla del `404`
        // de REQ-ORG-002 RB-6. Sin la prioridad explícita, un middleware de
        // ruta queda detrás del grupo `web` y el binding de un modelo del
        // tenant se resolvería sin organización fijada: no sería una fuga
        // —el global scope lanza— pero sería un 500 en lugar del 404 que la
        // regla exige, y con él la confirmación de que el recurso ajeno
        // existe.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);

        // Cada superficie declara de dónde sale su tenant (REQ-ORG-002 RB-4).
        // `ResolveCatalogTenant` y `ResolvePortalTenant` entran con su hito,
        // cuando existan `Catalog` y `Customer`.
        //
        // `outside-tenancy` no resuelve nada: declara que la ruta no
        // pertenece a ninguna superficie. La suite de REQ-QAX-008 exige que
        // toda ruta nuestra diga una cosa o la otra, porque no declarar nada
        // es indistinguible de un olvido.
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'without-tenant' => OperatesWithoutTenant::class,
            'outside-tenancy' => OutsideTenancy::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
