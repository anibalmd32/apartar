<?php

declare(strict_types=1);

use App\Actions\Organization\RegisterOrganizationData;

function registrationData(string $name, string $slug = '', string $email = 'ana@ejemplo.test'): RegisterOrganizationData
{
    return new RegisterOrganizationData(
        organizationName: $name,
        organizationSlug: $slug,
        ownerName: 'Ana',
        ownerEmail: $email,
        ownerPassword: 'ContraseñaLarga123',
    );
}

it('deriva el enlace del nombre cuando no se pidió ninguno', function (): void {
    expect(registrationData('Ferretería El Tornillo')->preferredSlug())->toBe('ferreteria-el-tornillo');
});

it('respeta el enlace pedido por encima del nombre', function (): void {
    expect(registrationData('Ferretería El Tornillo', 'el-tornillo')->preferredSlug())->toBe('el-tornillo');
});

it('normaliza también el enlace pedido', function (): void {
    expect(registrationData('Tienda', 'Mi Tienda')->preferredSlug())->toBe('mi-tienda');
});

it('devuelve vacío con un nombre de solo emojis', function (): void {
    expect(registrationData('🎉🎉🎉')->preferredSlug())->toBe('');
    expect(registrationData('¡¿!?')->preferredSlug())->toBe('');
});

it('normaliza el correo a minúsculas y sin espacios', function (): void {
    expect(registrationData('Tienda', '', '  Ana@Ejemplo.TEST ')->normalisedEmail())->toBe('ana@ejemplo.test');
});

it('no declara el teléfono, que se captura en ajustes', function (): void {
    $properties = array_map(
        fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(RegisterOrganizationData::class))->getProperties(),
    );

    expect($properties)->not->toContain('phone');
});
