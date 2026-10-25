<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Queries\Platform\PlatformMetricsQuery;
use App\Queries\Platform\SystemHealthQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function index(Request $request, PlatformMetricsQuery $metrics): Response
    {
        $days = in_array($request->integer('periodo'), PlatformMetricsQuery::PERIODS, true) ? $request->integer('periodo') : 30;

        return Inertia::render('platform/Dashboard', ['metrics' => $metrics->compute($days), 'periods' => PlatformMetricsQuery::PERIODS]);
    }

    public function health(SystemHealthQuery $health): Response
    {
        return Inertia::render('platform/Health', ['health' => $health->compute()]);
    }
}
