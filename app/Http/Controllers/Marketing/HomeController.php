<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Domain\Exchange\RateSource;
use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use Inertia\Inertia;
use Inertia\Response;

final class HomeController extends Controller
{
    public const RATE_DAYS = 6;

    private const RATE_ROWS_SCANNED = 60;

    public function __invoke(): Response
    {
        return Inertia::render('marketing/Home', [
            'rates' => $this->recentBcvRates(),
        ]);
    }

    /**
     * @return list<array{date: string, rate: string}>
     */
    private function recentBcvRates(): array
    {
        return ExchangeRate::query()
            ->where('source', RateSource::Bcv->value)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::RATE_ROWS_SCANNED)
            ->get(['rate', 'published_at'])
            ->unique(fn (ExchangeRate $rate): string => $rate->published_at->format('Y-m-d'))
            ->take(self::RATE_DAYS)
            ->map(fn (ExchangeRate $rate): array => [
                'date' => $rate->published_at->format('Y-m-d'),
                'rate' => $rate->rate,
            ])
            ->values()
            ->all();
    }
}
