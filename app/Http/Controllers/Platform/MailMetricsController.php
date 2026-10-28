<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Queries\Platform\MailFilters;
use App\Queries\Platform\MailMetricsQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MailMetricsController extends Controller
{
    public function index(Request $request, MailMetricsQuery $metrics): Response
    {
        $filters = MailFilters::fromRequest($request);
        [$from, $to] = $filters->range();

        return Inertia::render('platform/mail/Metrics', [
            'metrics' => $metrics->compute($from, $to),
            'filters' => ['desde' => $from->toDateString(), 'hasta' => $to->toDateString()],
        ]);
    }
}
