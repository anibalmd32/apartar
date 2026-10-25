<?php

declare(strict_types=1);

use App\Domain\Exchange\ExchangeRateProvider;
use App\Domain\Exchange\ExchangeRateUnavailableException;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use Carbon\CarbonImmutable;

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

it('declara en fetch() que lanza cuando la fuente no responde', function (): void {
    $doc = (string) (new ReflectionMethod(ExchangeRateProvider::class, 'fetch'))->getDocComment();

    expect($doc)->toContain('@throws');
    expect($doc)->toContain('ExchangeRateUnavailableException');
});

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
