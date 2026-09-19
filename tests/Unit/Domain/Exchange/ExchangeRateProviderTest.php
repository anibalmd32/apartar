<?php

declare(strict_types=1);

use App\Domain\Exchange\ExchangeRateProvider;
use App\Domain\Exchange\ExchangeRateUnavailableException;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use Carbon\CarbonImmutable;

/**
 * El contrato detrás del que se implementa **toda** fuente externa
 * (`REQ-FX-003` RB-5).
 *
 * Sus dos implementaciones llegan con `T-020`. Lo que se comprueba aquí es la
 * forma: una firma distinta de la del contrato del módulo 13 obligaría a
 * `ExchangeRateService` a saber con qué proveedor está hablando, que es
 * exactamente lo que la interfaz existe para evitar.
 */
it('declara fetch() y supports() con la firma del contrato', function (): void {
    $interface = new ReflectionClass(ExchangeRateProvider::class);

    expect($interface->isInterface())->toBeTrue();
    expect(array_keys($interface->getMethods()))->toHaveCount(2);

    $fetch = $interface->getMethod('fetch');
    expect((string) $fetch->getReturnType())->toBe(RateSnapshot::class);
    expect((string) $fetch->getParameters()[0]->getType())->toBe(RateSource::class);

    $supports = $interface->getMethod('supports');
    expect((string) $supports->getReturnType())->toBe('bool');
    expect((string) $supports->getParameters()[0]->getType())->toBe(RateSource::class);
});

/**
 * `fetch()` lanza y no devuelve `null`, y está escrito en su `@throws`: «la
 * fuente no respondió» es reintentable y «no hay ninguna tasa» no lo es, así
 * que un `null` que las confundiera dejaría al job reintentando un fallo que
 * no se arregla esperando.
 */
it('declara en fetch() que lanza cuando la fuente no responde', function (): void {
    $doc = (string) (new ReflectionMethod(ExchangeRateProvider::class, 'fetch'))->getDocComment();

    expect($doc)->toContain('@throws');
    expect($doc)->toContain('ExchangeRateUnavailableException');
});

/**
 * Una implementación mínima compila contra la interfaz sin adaptadores: es la
 * comprobación de que `T-020` puede escribir `DolarApiProvider` y
 * `ManualRateProvider` sin tocar este archivo.
 */
it('se puede implementar tal cual', function (): void {
    $provider = new class implements ExchangeRateProvider
    {
        public function fetch(RateSource $source): RateSnapshot
        {
            if (! $this->supports($source)) {
                throw ExchangeRateUnavailableException::unreachable($source, 'fuente no soportada');
            }

            return new RateSnapshot('42.50000000', $source, CarbonImmutable::now(), CarbonImmutable::now());
        }

        public function supports(RateSource $source): bool
        {
            return $source !== RateSource::Manual;
        }
    };

    expect($provider->fetch(RateSource::Bcv)->source)->toBe(RateSource::Bcv);
    expect($provider->supports(RateSource::Manual))->toBeFalse();

    expect(fn (): RateSnapshot => $provider->fetch(RateSource::Manual))
        ->toThrow(ExchangeRateUnavailableException::class);
});
