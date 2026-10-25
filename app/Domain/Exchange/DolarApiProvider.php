<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

final class DolarApiProvider implements ExchangeRateProvider
{
    private const FEEDS = [
        'oficial' => RateSource::Bcv->value,
        'paralelo' => RateSource::Parallel->value,
    ];

    /**
     * @var array<string, RateSnapshot>|null
     */
    private ?array $quotes = null;

    /**
     * @var array<string, string>
     */
    private array $discarded = [];

    /**
     * @param  list<string>  $fieldPriority
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $url,
        private readonly int $timeoutSeconds,
        private readonly array $fieldPriority,
    ) {}

    /**
     * @throws ExchangeRateUnavailableException
     */
    public function fetch(RateSource $source): RateSnapshot
    {
        if (! $this->supports($source)) {
            throw ExchangeRateUnavailableException::unreachable(
                $source,
                'DolarApi solo publica cotizaciones de plataforma y la tasa manual es un dato de cada negocio',
            );
        }

        $quotes = $this->quotes ??= $this->readAllQuotes();

        return $quotes[$source->value] ?? throw ExchangeRateUnavailableException::unexpectedPayload(
            $source,
            $this->discarded[$source->value] ?? sprintf(
                'falta la cotización "%s" en la respuesta',
                array_search($source->value, self::FEEDS, true),
            ),
        );
    }

    public function supports(RateSource $source): bool
    {
        return in_array($source->value, self::FEEDS, true);
    }

    /**
     * @return array<string, RateSnapshot>
     *
     * @throws ExchangeRateUnavailableException
     */
    private function readAllQuotes(): array
    {
        $payload = $this->request();
        $fetchedAt = CarbonImmutable::now();

        $quotes = [];

        foreach ($payload as $entry) {
            if (! is_array($entry) || ! is_string($entry['fuente'] ?? null)) {
                continue;
            }

            $source = self::FEEDS[$entry['fuente']] ?? null;

            if ($source === null) {
                continue;
            }

            try {
                $quotes[$source] = $this->snapshotOf(RateSource::from($source), $entry, $fetchedAt);
            } catch (ExchangeRateUnavailableException $exception) {
                $this->discarded[$source] = $exception->getMessage();
            }
        }

        if ($quotes === []) {
            throw ExchangeRateUnavailableException::unexpectedPayload(
                RateSource::Bcv,
                $this->discarded === []
                    ? 'ninguna cotización con una `fuente` reconocible (se esperaban "oficial" y "paralelo")'
                    : 'ninguna cotización utilizable: '.implode(' · ', $this->discarded),
            );
        }

        return $quotes;
    }

    /**
     * @return list<mixed>
     *
     * @throws ExchangeRateUnavailableException
     */
    private function request(): array
    {
        try {
            $response = $this->http->timeout($this->timeoutSeconds)->acceptJson()->get($this->url);
        } catch (Throwable $exception) {
            throw ExchangeRateUnavailableException::unreachable(RateSource::Bcv, $exception->getMessage());
        }

        if (! $response->successful()) {
            throw ExchangeRateUnavailableException::unreachable(
                RateSource::Bcv,
                sprintf('respondió %d', $response->status()),
            );
        }

        $payload = $response->json();

        if (! is_array($payload) || ! array_is_list($payload)) {
            throw ExchangeRateUnavailableException::unexpectedPayload(
                RateSource::Bcv,
                'se esperaba una lista JSON de cotizaciones',
            );
        }

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     *
     * @throws ExchangeRateUnavailableException
     */
    private function snapshotOf(RateSource $source, array $entry, CarbonImmutable $fetchedAt): RateSnapshot
    {
        return new RateSnapshot(
            rate: $this->rateOf($source, $entry),
            source: $source,
            publishedAt: $this->publishedAtOf($source, $entry),
            fetchedAt: $fetchedAt,
        );
    }

    /**
     * @param  array<array-key, mixed>  $entry
     *
     * @throws ExchangeRateUnavailableException
     */
    private function rateOf(RateSource $source, array $entry): string
    {
        foreach ($this->fieldPriority as $field) {
            $value = $entry[$field] ?? null;

            if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
                continue;
            }

            $rate = $this->decimalOf($value);

            if ($rate !== null && bccomp($rate, '0', 8) > 0) {
                return $rate;
            }
        }

        throw ExchangeRateUnavailableException::unexpectedPayload(
            $source,
            sprintf('ningún valor mayor que cero en %s', implode(', ', $this->fieldPriority)),
        );
    }

    private function decimalOf(int|float|string $value): ?string
    {
        $decimal = is_string($value) ? trim($value) : sprintf('%.8F', $value);

        return preg_match('/^-?(\d+(\.\d+)?|\.\d+)$/', $decimal) === 1 ? $decimal : null;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     *
     * @throws ExchangeRateUnavailableException
     */
    private function publishedAtOf(RateSource $source, array $entry): CarbonImmutable
    {
        $published = $entry['fechaActualizacion'] ?? null;

        if (! is_string($published) || $published === '') {
            throw ExchangeRateUnavailableException::unexpectedPayload($source, 'falta `fechaActualizacion`');
        }

        try {
            return CarbonImmutable::parse($published)->setTimezone(date_default_timezone_get());
        } catch (Throwable) {
            throw ExchangeRateUnavailableException::unexpectedPayload(
                $source,
                sprintf('`fechaActualizacion` no es una fecha ("%s")', $published),
            );
        }
    }
}
