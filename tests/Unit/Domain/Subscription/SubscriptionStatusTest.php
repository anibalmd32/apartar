<?php

declare(strict_types=1);

use App\Domain\Subscription\SubscriptionStatus;

it('declara los cinco estados del contrato', function (): void {
    $values = array_map(fn (SubscriptionStatus $status): string => $status->value, SubscriptionStatus::cases());

    expect($values)->toBe(['active', 'past_due', 'grace', 'suspended', 'cancelled']);
});

it('deja operar con normalidad durante la gracia por impago', function (): void {
    expect(SubscriptionStatus::PastDue->allowsTeamAccess())->toBeTrue();
    expect(SubscriptionStatus::PastDue->allowsCreation())->toBeTrue();
});

it('en suspensión deja entrar al equipo pero no crear', function (): void {
    expect(SubscriptionStatus::Suspended->allowsTeamAccess())->toBeTrue();
    expect(SubscriptionStatus::Suspended->allowsCreation())->toBeFalse();
});

it('cierra las dos puertas cuando la suscripción está cancelada', function (): void {
    expect(SubscriptionStatus::Cancelled->allowsTeamAccess())->toBeFalse();
    expect(SubscriptionStatus::Cancelled->allowsCreation())->toBeFalse();
});

it('no toca nada mientras la suscripción está al día', function (): void {
    expect(SubscriptionStatus::Active->allowsTeamAccess())->toBeTrue();
    expect(SubscriptionStatus::Active->allowsCreation())->toBeTrue();
});

it('trata la bajada pendiente como operación normal', function (): void {
    expect(SubscriptionStatus::Grace->allowsCreation())->toBeTrue();
    expect(SubscriptionStatus::Grace->isDelinquent())->toBeFalse();
});

it('sabe cuándo hay una deuda que regularizar', function (SubscriptionStatus $status, bool $expected): void {
    expect($status->isDelinquent())->toBe($expected);
})->with([
    [SubscriptionStatus::Active, false],
    [SubscriptionStatus::PastDue, true],
    [SubscriptionStatus::Grace, false],
    [SubscriptionStatus::Suspended, true],
    [SubscriptionStatus::Cancelled, false],
]);
