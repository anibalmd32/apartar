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

final class DashboardController extends Controller
{
    public function __invoke(Request $request, PortalContext $portal, CustomerBalanceQuery $balances, PortalAlertsQuery $alerts): Response
    {
        $blocks = $balances->forPortalContext($portal);
        $dashboard = new PortalDashboardResource($blocks, $alerts->forPortalContext($portal, $blocks), $portal);

        return Inertia::render('portal/Dashboard', $dashboard->resolve($request));
    }
}
