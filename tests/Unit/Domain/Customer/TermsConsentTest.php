<?php

declare(strict_types=1);

use App\Domain\Customer\TermsConsent;
use Carbon\CarbonImmutable;

it('guarda el instante y la IP de la aceptación', function (string $ip): void {
    $consent = new TermsConsent(CarbonImmutable::parse('2026-09-26 14:15:00', 'UTC'), $ip);

    expect($consent->ipAddress)->toBe($ip);
    expect($consent->acceptedAt->equalTo(CarbonImmutable::parse('2026-09-26 14:15:00', 'UTC')))->toBeTrue();
})->with([
    'IPv4' => ['190.202.10.5'],
    'IPv6' => ['2800:810:43b:1a2::5'],
]);

it('lleva el instante a la zona de la aplicación sin moverlo', function (): void {
    $utc = CarbonImmutable::parse('2026-09-26 14:15:00', 'UTC');

    $consent = new TermsConsent($utc, '190.202.10.5');

    expect($consent->acceptedAt->getTimezone()->getName())->toBe(date_default_timezone_get());
    expect($consent->acceptedAt->getTimestamp())->toBe($utc->getTimestamp());
});

it('no se construye sin una IP que lo sea', function (string $ip): void {
    new TermsConsent(CarbonImmutable::now(), $ip);
})->with([
    'vacía' => [''],
    'un nombre de host' => ['proxy.interno'],
    'una lista de X-Forwarded-For' => ['190.202.10.5, 10.0.0.1'],
])->throws(InvalidArgumentException::class);

it('construye la aceptación de ahora', function (): void {
    CarbonImmutable::setTestNow('2026-09-26 10:00:00');

    try {
        $consent = TermsConsent::acceptedNowFrom('190.202.10.5');

        expect($consent->acceptedAt->equalTo(CarbonImmutable::now()))->toBeTrue();
    } finally {
        CarbonImmutable::setTestNow();
    }
});
