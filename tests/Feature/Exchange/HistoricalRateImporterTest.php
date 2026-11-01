<?php

declare(strict_types=1);

use App\Domain\Exchange\HistoricalRateImporter;
use App\Domain\Exchange\RateSource;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ReusesMigratedDatabase;

uses(ReusesMigratedDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-31 12:00:00');
    config()->set('services.exchange_rate.history_url', 'https://historicos.test/dolares');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return list<array<string, mixed>>
 */
function historyAnswer(): array
{
    return [
        ['fuente' => 'oficial', 'compra' => null, 'venta' => null, 'promedio' => 36.5, 'fecha' => '2024-03-01'],
        ['fuente' => 'oficial', 'compra' => null, 'venta' => null, 'promedio' => 36.72, 'fecha' => '2024-03-04'],
        ['fuente' => 'paralelo', 'compra' => null, 'venta' => null, 'promedio' => 39.1, 'fecha' => '2024-03-01'],
        ['fuente' => 'oficial', 'compra' => null, 'venta' => null, 'promedio' => 889.5754, 'fecha' => '2026-11-02'],
        ['fuente' => 'oficial', 'compra' => null, 'venta' => null, 'promedio' => 0, 'fecha' => '2024-03-05'],
        ['fuente' => 'otra', 'compra' => null, 'venta' => null, 'promedio' => 10, 'fecha' => '2024-03-05'],
        ['fuente' => 'oficial', 'compra' => null, 'venta' => null, 'promedio' => 12, 'fecha' => 'ayer'],
    ];
}

it('guarda cada día del histórico una sola vez, a medianoche de Caracas, sin fechas futuras ni basura', function (): void {
    Http::fake(['historicos.test/*' => Http::response(historyAnswer())]);

    expect(app(HistoricalRateImporter::class)->import())->toBe(['bcv' => 2, 'parallel' => 1])
        ->and(app(HistoricalRateImporter::class)->import())->toBe(['bcv' => 0, 'parallel' => 0]);

    $bcv = ExchangeRate::query()->where('source', RateSource::Bcv->value)->orderBy('published_at')->get();

    expect($bcv->map(fn (ExchangeRate $row): array => [$row->rate, $row->published_at->setTimezone('America/Caracas')->format('Y-m-d H:i')])->all())
        ->toBe([['36.50000000', '2024-03-01 00:00'], ['36.72000000', '2024-03-04 00:00']]);
});

it('no duplica un día que ya trajo la consulta en vivo', function (): void {
    ExchangeRate::query()->create(['source' => RateSource::Bcv, 'rate' => '36.50000000', 'published_at' => CarbonImmutable::parse('2024-03-01 00:00', 'America/Caracas')]);
    Http::fake(['historicos.test/*' => Http::response(historyAnswer())]);

    expect(app(HistoricalRateImporter::class)->import()['bcv'])->toBe(1);
});

it('falla en voz alta si el servicio no responde', function (): void {
    Http::fake(['historicos.test/*' => Http::response('caído', 503)]);

    app(HistoricalRateImporter::class)->import();
})->throws(Illuminate\Http\Client\RequestException::class);
