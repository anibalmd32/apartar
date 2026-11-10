<?php

declare(strict_types=1);

use App\Actions\Payment\SafeguardData;
use App\Domain\Safeguard\SafeguardDestination;

it('recorta la referencia y la nota y deja vacías en nulo', function (): void {
    $data = new SafeguardData(SafeguardDestination::BinanceUsdt, '  12345  ', null, '   ');

    expect($data->reference)->toBe('12345')
        ->and($data->note)->toBeNull()
        ->and($data->removeProof)->toBeFalse();
});

it('no acepta una referencia o una nota demasiado largas', function (string $reference, string $note): void {
    new SafeguardData(SafeguardDestination::Zelle, $reference, null, $note);
})->throws(InvalidArgumentException::class)->with([
    'referencia' => [str_repeat('1', SafeguardData::REFERENCE_MAX + 1), ''],
    'nota' => ['', str_repeat('a', SafeguardData::NOTE_MAX + 1)],
]);
