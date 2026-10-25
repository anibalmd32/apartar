<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Exchange\ExchangeRateProvider;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\ExchangeRateUnavailableException;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Models\ExchangeRate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class FetchExchangeRate implements ShouldQueue
{
    use Queueable;

    private const MAX_VARIATION = '0.30';

    private const SCALE = 8;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct()
    {
        $this->onQueue('high');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('exchange-rate-ingest'))->dontRelease()->expireAfter(120)];
    }

    /**
     * @throws ExchangeRateUnavailableException
     */
    public function handle(ExchangeRateProvider $provider, ExchangeRateService $rates): void
    {
        $failures = [];

        foreach (RateSource::platform() as $source) {
            try {
                $this->ingest($provider->fetch($source), $rates);
            } catch (ExchangeRateUnavailableException $exception) {
                $failures[$source->value] = $exception;
            }
        }

        if ($failures !== []) {
            Log::error('No se pudo leer la tasa de alguna fuente; se conserva la anterior.', [
                'sources' => array_keys($failures),
                'attempt' => $this->attempts(),
            ]);

            throw reset($failures);
        }
    }

    private function ingest(RateSnapshot $snapshot, ExchangeRateService $rates): void
    {
        $previous = $this->lastPersisted($snapshot->source);

        if (! $this->isPlausible($snapshot, $previous)) {
            return;
        }

        if (! $this->isNew($snapshot, $previous)) {
            $rates->remember($snapshot);

            return;
        }

        $rates->remember($this->persist($snapshot));
    }

    private function persist(RateSnapshot $snapshot): RateSnapshot
    {
        $row = ExchangeRate::query()->create([
            'source' => $snapshot->source,
            'rate' => $snapshot->rate,
            'published_at' => $snapshot->publishedAt,
        ]);

        $row->refresh();

        return $this->snapshotOf($row);
    }

    private function snapshotOf(ExchangeRate $row): RateSnapshot
    {
        return new RateSnapshot(
            rate: $row->rate,
            source: $row->source,
            publishedAt: $row->published_at,
            fetchedAt: $row->fetched_at,
        );
    }

    private function isPlausible(RateSnapshot $snapshot, ?ExchangeRate $previous): bool
    {
        if (bccomp($snapshot->rate, '0', self::SCALE) <= 0) {
            Log::error('Tasa rechazada por no ser positiva; se conserva la anterior.', [
                'source' => $snapshot->source->value,
                'rate' => $snapshot->rate,
            ]);

            return false;
        }

        if ($previous === null) {
            return true;
        }

        $variation = $this->variationBetween($previous->rate, $snapshot->rate);

        if ($variation === null || bccomp($variation, self::MAX_VARIATION, self::SCALE) <= 0) {
            return true;
        }

        if ($this->snapshotOf($previous)->isStale()) {
            Log::warning('Guarda de variación abstenida: la referencia ya está obsoleta y no puede vetar.', [
                'source' => $snapshot->source->value,
                'rate' => $snapshot->rate,
                'previous' => $previous->rate,
                'previous_published_at' => $previous->published_at->toIso8601String(),
                'variation' => $variation,
                'max' => self::MAX_VARIATION,
            ]);

            return true;
        }

        Log::error('Tasa rechazada por variación implausible; se conserva la anterior.', [
            'source' => $snapshot->source->value,
            'rate' => $snapshot->rate,
            'previous' => $previous->rate,
            'previous_published_at' => $previous->published_at->toIso8601String(),
            'variation' => $variation,
            'max' => self::MAX_VARIATION,
        ]);

        return false;
    }

    private function variationBetween(string $previous, string $current): ?string
    {
        if (bccomp($previous, '0', self::SCALE) === 0) {
            return null;
        }

        $difference = bcsub($current, $previous, self::SCALE);

        if (bccomp($difference, '0', self::SCALE) < 0) {
            $difference = bcmul($difference, '-1', self::SCALE);
        }

        return bcdiv($difference, $previous, self::SCALE);
    }

    private function isNew(RateSnapshot $snapshot, ?ExchangeRate $previous): bool
    {
        if ($previous === null) {
            return true;
        }

        return bccomp($snapshot->rate, $previous->rate, self::SCALE) !== 0
            || ! $previous->published_at->equalTo($snapshot->publishedAt);
    }

    private function lastPersisted(RateSource $source): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('source', $source->value)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();
    }
}
