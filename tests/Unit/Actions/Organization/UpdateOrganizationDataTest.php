<?php

declare(strict_types=1);

use App\Actions\Organization\UpdateOrganizationData;

function identityData(?string $brandColor): UpdateOrganizationData
{
    return new UpdateOrganizationData(
        name: 'Ferretería El Tornillo',
        slug: 'ferreteria-el-tornillo',
        document: null,
        phone: null,
        timezone: 'America/Caracas',
        brandColor: $brandColor,
        storefrontEnabled: false,
    );
}

it('normaliza el color a seis dígitos en minúsculas', function (?string $written, ?string $stored): void {
    expect(identityData($written)->normalisedBrandColor())->toBe($stored);
})->with([
    'forma corta' => ['#ABC', '#aabbcc'],
    'forma corta en minúsculas' => ['#abc', '#aabbcc'],
    'forma larga en mayúsculas' => ['#1F6FEB', '#1f6feb'],
    'forma larga ya normalizada' => ['#1f6feb', '#1f6feb'],
    'con espacios' => ['  #1F6FEB  ', '#1f6feb'],
]);

it('trata el color vacío como ausente', function (?string $written): void {
    expect(identityData($written)->normalisedBrandColor())->toBeNull();
})->with([
    'nulo' => [null],
    'vacío' => [''],
    'solo espacios' => ['   '],
]);

it('lleva el teléfono, que en el registro no se pide', function (): void {
    $data = new UpdateOrganizationData(
        name: 'Ferretería El Tornillo',
        slug: 'ferreteria-el-tornillo',
        document: 'J-12345678-9',
        phone: '0414-1234567',
        timezone: 'America/Caracas',
        brandColor: null,
        storefrontEnabled: true,
    );

    expect($data->phone)->toBe('0414-1234567');
    expect($data->storefrontEnabled)->toBeTrue();
    expect($data->logo)->toBeNull();
});
