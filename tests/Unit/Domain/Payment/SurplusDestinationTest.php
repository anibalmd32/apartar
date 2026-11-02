<?php

declare(strict_types=1);

use App\Domain\Payment\SurplusDestination;
use App\Domain\Payment\SurplusDestinationType;
use App\Models\User;

it('guarda el destino tal cual y recorta la nota', function (): void {
    $destination = new SurplusDestination(SurplusDestinationType::Other, note: '  Un paquete de load  ');

    expect($destination->type)->toBe(SurplusDestinationType::Other)
        ->and($destination->teamMember)->toBeNull()
        ->and($destination->noteText())->toBe('Un paquete de load');
});

it('deja la nota vacía en null', function (): void {
    $destination = new SurplusDestination(SurplusDestinationType::Tip, note: '   ');

    expect($destination->noteText())->toBeNull();
});

it('acepta una persona del equipo', function (): void {
    $member = new User;
    $destination = new SurplusDestination(SurplusDestinationType::TeamMember, $member);

    expect($destination->teamMember)->toBe($member);
});
