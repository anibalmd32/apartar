<?php

declare(strict_types=1);

use App\Domain\Layaway\DownPaymentWindow;
use Carbon\CarbonImmutable;

it('vence con el plazo del negocio si no se elige otro', function (): void {
    $window = DownPaymentWindow::organizationDefault();
    $now = CarbonImmutable::parse('2026-09-27 10:00:00');

    expect($window->isOrganizationDefault())->toBeTrue()
        ->and($window->expiresAt($now, 72)?->toDateTimeString())->toBe('2026-09-30 10:00:00')
        ->and($window->describe(72))->toBe('plazo del negocio (72 h)');
});

it('vence en las horas o días elegidos', function (): void {
    $now = CarbonImmutable::parse('2026-09-27 10:00:00');

    expect(DownPaymentWindow::hours(6)->expiresAt($now, 72)?->toDateTimeString())->toBe('2026-09-27 16:00:00')
        ->and(DownPaymentWindow::days(3)->expiresAt($now, 72)?->toDateTimeString())->toBe('2026-09-30 10:00:00')
        ->and(DownPaymentWindow::hours(1)->describe(72))->toBe('1 hora')
        ->and(DownPaymentWindow::hours(30)->describe(72))->toBe('30 horas')
        ->and(DownPaymentWindow::days(1)->describe(72))->toBe('1 día')
        ->and(DownPaymentWindow::days(4)->describe(72))->toBe('4 días');
});

it('sin vencimiento no tiene fecha', function (): void {
    $window = DownPaymentWindow::withoutExpiry();

    expect($window->expiresAt(CarbonImmutable::now(), 72))->toBeNull()
        ->and($window->isOrganizationDefault())->toBeFalse()
        ->and($window->describe(72))->toBe('sin vencimiento');
});

it('rechaza plazos fuera de rango', function (Closure $build): void {
    $build();
})->throws(InvalidArgumentException::class)->with([
    'cero horas' => [fn () => DownPaymentWindow::hours(0)],
    'demasiadas horas' => [fn () => DownPaymentWindow::hours(DownPaymentWindow::MAX_HOURS + 1)],
    'cero días' => [fn () => DownPaymentWindow::days(0)],
    'demasiados días' => [fn () => DownPaymentWindow::days(DownPaymentWindow::MAX_DAYS + 1)],
]);
