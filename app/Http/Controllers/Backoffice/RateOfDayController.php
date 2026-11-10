<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\PaymentDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RateOfDayController extends Controller
{
    public function __invoke(Request $request, ExchangeRateService $rates, TenantContext $context): JsonResponse
    {
        $organization = $context->organization();
        $day = PaymentDay::fromQuery($request, $organization->timezone);
        $rate = $rates->on($organization, $day, RateSource::tryFrom($request->string('fuente')->toString()));

        return response()->json(['rate' => $rate instanceof RateSnapshot ? [
            'rate' => $rate->rate,
            'sourceLabel' => $rate->source->label(),
            'date' => $day->toDateString(),
        ] : null]);
    }
}
