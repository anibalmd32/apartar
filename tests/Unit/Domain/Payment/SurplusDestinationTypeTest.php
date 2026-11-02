<?php

declare(strict_types=1);

use App\Domain\Payment\SurplusDestinationType;

it('describe sus destinos con su nombre corto', function (): void {
    expect(SurplusDestinationType::TeamMember->label())->toBe('Una persona del equipo')
        ->and(SurplusDestinationType::TeamMember->shortLabel())->toBe('el equipo')
        ->and(SurplusDestinationType::Tip->shortLabel())->toBe('Propina')
        ->and(SurplusDestinationType::PettyCash->shortLabel())->toBe('Caja chica')
        ->and(SurplusDestinationType::Other->shortLabel())->toBe('Otro');
});

it('solo «Otro» exige nota y solo el equipo exige persona', function (): void {
    expect(SurplusDestinationType::Other->requiresNote())->toBeTrue()
        ->and(SurplusDestinationType::Tip->requiresNote())->toBeFalse()
        ->and(SurplusDestinationType::PettyCash->requiresNote())->toBeFalse()
        ->and(SurplusDestinationType::TeamMember->requiresNote())->toBeFalse()
        ->and(SurplusDestinationType::TeamMember->requiresUser())->toBeTrue()
        ->and(SurplusDestinationType::Other->requiresUser())->toBeFalse();
});

it('rechaza «Otro» sin nota', function (): void {
    SurplusDestinationType::assertNote('other', null);
})->throws(InvalidArgumentException::class, 'necesita una nota corta');

it('acepta «Otro» con nota y los demás sin ella', function (): void {
    SurplusDestinationType::assertNote('other', 'Un paquete de load');
    SurplusDestinationType::assertNote('tip', null);
    SurplusDestinationType::assertNote('petty_cash', null);
    SurplusDestinationType::assertNote('team_member', null);

    expect(true)->toBeTrue();
});
