<?php

declare(strict_types=1);

use App\Actions\Organization\RegisterOrganizationData;

/**
 * Normalización de lo que llega del formulario (`REQ-ORG-001`).
 */
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

/**
 * SP-7 · un nombre que no deja nada convertible devuelve vacío, y ese vacío es
 * la señal de que hace falta escribir el enlace a mano.
 */
it('devuelve vacío con un nombre de solo emojis', function (): void {
    expect(registrationData('🎉🎉🎉')->preferredSlug())->toBe('');
    expect(registrationData('¡¿!?')->preferredSlug())->toBe('');
});

/**
 * RB-3 · `users.email` es único en toda la plataforma. Sin normalizar,
 * `Ana@…` y `ana@…` serían dos cuentas de equipo distintas y el login dejaría
 * de ser inequívoco.
 */
it('normaliza el correo a minúsculas y sin espacios', function (): void {
    expect(registrationData('Tienda', '', '  Ana@Ejemplo.TEST ')->normalisedEmail())->toBe('ana@ejemplo.test');
});

/**
 * El teléfono **ya no se declara aquí** (`T-014`).
 *
 * Estuvo como propiedad huérfana desde `T-010`: el contrato de `REQ-ORG-001`
 * lo menciona, pero no había columna donde guardarlo y el formulario no lo
 * pedía, así que era un parámetro que nadie escribía ni leía. Se resolvió por
 * el otro lado —`organizations.phone` existe y se captura en la pantalla de
 * ajustes (`REQ-ORG-006`)— porque RB-8 quiere un alta sin fricción.
 *
 * El test se queda como guarda: si alguien lo devuelve al alta, aquí se ve.
 */
it('no declara el teléfono, que se captura en ajustes', function (): void {
    $properties = array_map(
        fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(RegisterOrganizationData::class))->getProperties(),
    );

    expect($properties)->not->toContain('phone');
});
