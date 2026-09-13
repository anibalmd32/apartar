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
 * El teléfono está en el contrato de la spec y hoy no tiene columna: se queda
 * declarado y sin recoger, en vez de pedirse para perderse.
 */
it('acepta el teléfono del contrato sin exigirlo', function (): void {
    expect(registrationData('Tienda')->phone)->toBeNull();

    $withPhone = new RegisterOrganizationData(
        organizationName: 'Tienda',
        organizationSlug: '',
        ownerName: 'Ana',
        ownerEmail: 'ana@ejemplo.test',
        ownerPassword: 'ContraseñaLarga123',
        phone: '0414-1234567',
    );

    expect($withPhone->phone)->toBe('0414-1234567');
});
