<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class HistoricalRateImporter
{
    public const TIMEZONE = 'America/Caracas';

    private const FEEDS = [
        'oficial' => RateSource::Bcv,
        'paralelo' => RateSource::Parallel,
    ];

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $url,
        private readonly int $timeoutSeconds,
    ) {}

    /**
     * @return array<string, int>
     *
     * @throws RuntimeException
     */
    public function import(): array
    {
        $answer = $this->http->timeout($this->timeoutSeconds)->acceptJson()->get($this->url)->throw()->json();

        if (! is_array($answer)) {
            throw new RuntimeException('El histórico de tasas no devolvió una lista.');
        }

        $today = CarbonImmutable::now(self::TIMEZONE)->toDateString();
        $quotes = [];

        foreach ($answer as $row) {
            $source = is_array($row) && is_string($row['fuente'] ?? null) ? (self::FEEDS[$row['fuente']] ?? null) : null;
            $date = is_array($row) && is_string($row['fecha'] ?? null) ? $row['fecha'] : '';
            $average = is_array($row) ? ($row['promedio'] ?? null) : null;

            if ($source === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $date > $today || ! is_numeric($average) || (float) $average <= 0) {
                continue;
            }

            $quotes[$source->value][$date] = number_format((float) $average, 8, '.', '');
        }

        $inserted = [];

        foreach ($quotes as $source => $byDate) {
            $known = $this->knownDates($source, (string) min(array_keys($byDate)));
            $now = CarbonImmutable::now();
            $rows = [];

            foreach ($byDate as $date => $rate) {
                if (isset($known[$date])) {
                    continue;
                }

                $rows[] = [
                    'source' => $source,
                    'rate' => $rate,
                    'published_at' => CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay()->setTimezone(self::storageTimezone()),
                    'fetched_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('exchange_rates')->insert($chunk);
            }

            $inserted[$source] = count($rows);
        }

        return $inserted;
    }

    private static function storageTimezone(): string
    {
        return date_default_timezone_get();
    }

    /**
     * @return array<string, true>
     */
    private function knownDates(string $source, string $since): array
    {
        $known = [];

        ExchangeRate::query()
            ->where('source', $source)
            ->where('published_at', '>=', CarbonImmutable::parse($since, self::TIMEZONE)->startOfDay()->setTimezone(self::storageTimezone()))
            ->pluck('published_at')
            ->each(function (mixed $publishedAt) use (&$known): void {
                $known[CarbonImmutable::parse($publishedAt)->setTimezone(self::TIMEZONE)->toDateString()] = true;
            });

        return $known;
    }
}
