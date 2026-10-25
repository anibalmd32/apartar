<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\RateSource;
use App\Domain\Exchange\RateView;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExchangeRateController extends Controller
{
    public const CACHE_SECONDS = 60;

    public function __invoke(Request $request, ExchangeRateService $rates): JsonResponse
    {
        $validated = $request->validate([
            'source' => [
                'sometimes',
                'string',
                Rule::in(array_map(fn (RateSource $source): string => $source->value, self::publicSources())),
            ],
        ]);

        $source = isset($validated['source']) && is_string($validated['source'])
            ? RateSource::from($validated['source'])
            : RateSource::Bcv;

        $view = RateView::of($rates->forSource($source), $source);

        return response()
            ->json($view->toArray())
            ->header('Cache-Control', sprintf('public, max-age=%d', self::CACHE_SECONDS));
    }

    /**
     * @return list<RateSource>
     */
    public static function publicSources(): array
    {
        return RateSource::platform();
    }
}
