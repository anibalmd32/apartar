<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Account\PortalContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\PortalDashboardResource;
use App\Queries\CustomerBalanceQuery;
use App\Queries\PortalAlertsQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La portada del portal (`REQ-LAY-020`).
 *
 * Qué fichas ve la sesión lo decide `PortalContext`; qué tiene la persona con
 * cada negocio, `CustomerBalanceQuery` —una consulta por organización—; qué
 * hay que avisarle, `PortalAlertsQuery` sobre esos mismos bloques
 * (`REQ-LAY-023`), y cómo se pinta, `PortalDashboardResource`. Aquí solo se
 * juntan.
 *
 * Detrás de `portal` y `portal-tenant`: con la sesión acotada, el tenant ya es
 * el de la ficha. Las props no llevan ningún `id` (RB-10).
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request, PortalContext $portal, CustomerBalanceQuery $balances, PortalAlertsQuery $alerts): Response
    {
        $blocks = $balances->forPortalContext($portal);
        $dashboard = new PortalDashboardResource($blocks, $alerts->forPortalContext($portal, $blocks), $portal);

        return Inertia::render('portal/Dashboard', $dashboard->resolve($request));
    }
}
