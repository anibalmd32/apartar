<?php

declare(strict_types=1);

use App\Actions\Organization\UpdateOrganizationData;

/**
 * La identidad ya normalizada que recibe `UpdateOrganizationAction`
 * (`REQ-ORG-006`).
 *
 * Sin base de datos: aquí solo se comprueba la normalización, que es lo único
 * que esta clase decide.
 */
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

/**
 * **`#FFF` y `#ffffff` son el mismo color.** Guardarlo de dos formas
 * convertiría cualquier comparación futura —«¿cambió el branding?»— en una
 * respuesta falsa, y ese tipo de falso positivo no lo detecta nadie mirando la
 * pantalla, porque el color se ve igual.
 */
it('normaliza el color a seis dígitos en minúsculas', function (?string $written, ?string $stored): void {
    expect(identityData($written)->normalisedBrandColor())->toBe($stored);
})->with([
    'forma corta' => ['#ABC', '#aabbcc'],
    'forma corta en minúsculas' => ['#abc', '#aabbcc'],
    'forma larga en mayúsculas' => ['#1F6FEB', '#1f6feb'],
    'forma larga ya normalizada' => ['#1f6feb', '#1f6feb'],
    'con espacios' => ['  #1F6FEB  ', '#1f6feb'],
]);

/**
 * Un campo opcional que llega vacío no es «cadena vacía»: es que no lo
 * rellenaron. Guardarlo como `''` obligaría a que cada lectura futura tratara
 * dos valores como el mismo ausente.
 */
it('trata el color vacío como ausente', function (?string $written): void {
    expect(identityData($written)->normalisedBrandColor())->toBeNull();
})->with([
    'nulo' => [null],
    'vacío' => [''],
    'solo espacios' => ['   '],
]);

/**
 * **Sí declara `phone`**, a diferencia de `RegisterOrganizationData`. La
 * columna llega con `T-014` y es esta pantalla la que la captura: en el alta no
 * se pide, porque `REQ-ORG-001` RB-8 quiere un registro sin fricción y el
 * teléfono pertenece al perfil del negocio.
 */
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
