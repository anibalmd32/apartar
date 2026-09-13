<?php

declare(strict_types=1);

use App\Domain\Subscription\SubscriptionStatus;

/**
 * Los cinco estados de `REQ-SUB-007` y lo que cada uno permite.
 *
 * Los dos métodos hablan **solo del equipo del negocio**. La regla que no se
 * negocia (`Q7`, RB-3) es que ningún estado de esta lista interrumpe jamás un
 * apartado en curso, el portal del cliente final ni la subida de comprobantes:
 * si un negocio deja de pagar, el perjudicado no puede ser el cliente que le
 * debe dinero a él.
 */
it('declara los cinco estados del contrato', function (): void {
    $values = array_map(fn (SubscriptionStatus $status): string => $status->value, SubscriptionStatus::cases());

    expect($values)->toBe(['active', 'past_due', 'grace', 'suspended', 'cancelled']);
});

/**
 * En `past_due` **no se pierde nada** (RB-2): la gracia existe para que un
 * atraso no paralice el negocio, con un aviso persistente y sin cortar nada.
 */
it('deja operar con normalidad durante la gracia por impago', function (): void {
    expect(SubscriptionStatus::PastDue->allowsTeamAccess())->toBeTrue();
    expect(SubscriptionStatus::PastDue->allowsCreation())->toBeTrue();
});

/**
 * El caso que distingue a los dos métodos: en `suspended` el equipo **entra**
 * —`REQ-ORG-008` RB-3 le conserva la lectura de la cobranza y la aprobación de
 * comprobantes— pero no **crea** nada (`REQ-SUB-004` RB-8). Cobrar lo que ya
 * le deben no puede quedar bloqueado por deberle una suscripción a la
 * plataforma.
 */
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

/**
 * `grace` es una bajada de plan pendiente de aplicar, no un impago: mientras
 * tanto se opera igual.
 */
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
