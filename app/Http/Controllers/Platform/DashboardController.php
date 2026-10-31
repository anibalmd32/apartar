<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Queries\Platform\MailMetricsQuery;
use App\Queries\Platform\PlatformMetricsQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class DashboardController extends Controller
{
    public function index(Request $request, PlatformMetricsQuery $metrics, MailMetricsQuery $mail): Response
    {
        $days = in_array($request->integer('periodo'), PlatformMetricsQuery::PERIODS, true) ? $request->integer('periodo') : 30;

        return Inertia::render('platform/Dashboard', [
            'metrics' => $metrics->compute($days),
            'periods' => PlatformMetricsQuery::PERIODS,
            'mail' => fn (): ?array => $this->mailSummary($mail),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mailSummary(MailMetricsQuery $mail): ?array
    {
        try {
            return $mail->summary();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
