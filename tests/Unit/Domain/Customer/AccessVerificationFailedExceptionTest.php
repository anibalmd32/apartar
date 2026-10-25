<?php

declare(strict_types=1);

use App\Domain\Customer\AccessVerificationFailedException;

it('dice lo mismo sea cual sea el motivo, y guarda el motivo para el log', function (): void {
    $missing = new AccessVerificationFailedException('código inexistente');
    $foreign = new AccessVerificationFailedException('el documento no es el de la ficha del apartado');

    expect($missing->getMessage())->toBe($foreign->getMessage())
        ->and($missing->getMessage())->toBe(AccessVerificationFailedException::MESSAGE)
        ->and($missing->getMessage())->not->toContain('existe')
        ->and($foreign->reason)->toBe('el documento no es el de la ficha del apartado');
});
