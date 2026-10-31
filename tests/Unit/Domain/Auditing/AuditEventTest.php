<?php

declare(strict_types=1);

use App\Domain\Auditing\AuditArea;
use App\Domain\Auditing\AuditEvent;

function sourceOfEmitter(string $class): ?string
{
    if (! class_exists($class)) {
        return null;
    }

    $file = (new ReflectionClass($class))->getFileName();

    return $file === false ? null : (string) file_get_contents($file);
}

it('declara los eventos de los seis bloques', function (): void {
    foreach (AuditArea::cases() as $area) {
        expect($area->events())->not->toBeEmpty("El bloque {$area->value} se quedó sin eventos.");
    }

    $grouped = array_merge(...array_map(fn (AuditArea $area): array => $area->events(), AuditArea::cases()));

    expect($grouped)->toHaveCount(count(AuditEvent::cases()));
});

it('exige que toda Action ya escrita audite el evento que declara', function (AuditEvent $event): void {
    $source = sourceOfEmitter($event->emitter());

    if ($source === null) {
        expect(true)->toBeTrue();

        return;
    }

    expect(str_contains($source, 'LogsSensitiveActivity'))->toBeTrue(sprintf(
        '%s emite %s y no usa LogsSensitiveActivity.',
        $event->emitter(),
        $event->value,
    ));

    expect(str_contains($source, 'AuditEvent::'.$event->name))->toBeTrue(sprintf(
        '%s existe y no emite %s. Añade la llamada a audit() o cambia el emisor en AuditEvent.',
        $event->emitter(),
        $event->value,
    ));
})->with(AuditEvent::cases());

it('cubre los cuarenta y un eventos: todos tienen ya su Action', function (): void {
    $covered = array_values(array_filter(
        AuditEvent::cases(),
        fn (AuditEvent $event): bool => class_exists($event->emitter()),
    ));

    expect($covered)->toHaveCount(41);

    $areas = array_values(array_unique(
        array_map(fn (AuditEvent $event): string => $event->area()->value, $covered),
    ));

    expect($areas)->toEqualCanonicalizing([
        AuditArea::Organization->value,
        AuditArea::Catalog->value,
        AuditArea::Customers->value,
        AuditArea::Collections->value,
        AuditArea::Subscription->value,
        AuditArea::Platform->value,
    ]);
});

it('declara los cuarenta y un eventos de la lista cerrada', function (): void {
    expect(AuditEvent::cases())->toHaveCount(41);
    expect(AuditEvent::CustomerContactUpdated->value)->toBe('customer.contact_updated');
});

it('declara cada emisor como una Action con espacio de nombres propio', function (AuditEvent $event): void {
    expect($event->emitter())
        ->toStartWith('App\\Actions\\')
        ->toEndWith('Action')
        ->toMatch('/^App\\\\Actions\\\\[A-Z][A-Za-z]+\\\\[A-Z][A-Za-z]+Action$/');
})->with(AuditEvent::cases());

it('nombra cada evento para una persona', function (AuditEvent $event): void {
    expect($event->label())->not->toBe('')->and($event->label())->not->toBe($event->value);
})->with(AuditEvent::cases());

it('mantiene los valores del bloque Organización, que ya hay filas escritas con ellos', function (): void {
    $organization = array_map(
        fn (AuditEvent $event): string => $event->value,
        AuditArea::Organization->events(),
    );

    expect($organization)->toBe([
        'organization.settings_updated',
        'organization.identity_updated',
        'payment_account.created',
        'payment_account.updated',
        'payment_account.removed',
        'team_member.invited',
        'team_member.invitation_revoked',
        'team_member.role_changed',
        'team_member.enabled',
        'team_member.disabled',
        'organization.closed',
    ]);
});
