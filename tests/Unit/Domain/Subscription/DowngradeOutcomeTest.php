<?php

declare(strict_types=1);

use App\Domain\Subscription\DowngradeOutcome;
use Carbon\CarbonImmutable;

it('distingue una pasada que no hizo nada de una que aplicó o despublicó', function (): void {
    expect(DowngradeOutcome::nothing()->isNoop())->toBeTrue()
        ->and(DowngradeOutcome::nothing()->applied())->toBeFalse();

    $applied = new DowngradeOutcome('pro', 'free', [], CarbonImmutable::parse('2026-11-08 00:05:00'));
    expect($applied->applied())->toBeTrue()->and($applied->isNoop())->toBeFalse();

    $unpublished = new DowngradeOutcome(null, null, ['ofertas'], null);
    expect($unpublished->applied())->toBeFalse()->and($unpublished->isNoop())->toBeFalse();
});

it('despublica lo que sobre siete días después de aplicar la bajada', function (): void {
    expect(DowngradeOutcome::unpublishDateFor(CarbonImmutable::parse('2026-11-01 00:05:00'))->toDateTimeString())
        ->toBe('2026-11-08 00:05:00');
});

it('sin aplicación, la gracia es su default', function (): void {
    expect(DowngradeOutcome::GRACE_DAYS)->toBe(7)->and(DowngradeOutcome::graceDays())->toBe(7);
});
