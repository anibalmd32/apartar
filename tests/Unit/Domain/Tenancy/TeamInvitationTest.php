<?php

declare(strict_types=1);

use App\Domain\Tenancy\TeamInvitation;
use App\Models\Organization;
use Illuminate\Support\Carbon;

/**
 * El enlace de invitación como credencial (`REQ-ORG-004` RB-4).
 *
 * Sin base de datos: `TeamInvitation` no consulta nada, y la organización solo
 * le presta su identificador. Lo que se comprueba aquí es lo que hace que el
 * enlace sea seguro y resoluble, que son dos propiedades del propio token.
 */
it('emite un token con el identificador de su organización delante', function (): void {
    $organization = new Organization;
    $organization->forceFill(['id' => 42]);

    $invitation = TeamInvitation::issueFor($organization);

    expect($invitation->plainToken)->toStartWith('42.');
    expect(TeamInvitation::organizationIdIn($invitation->plainToken))->toBe(42);
});

/**
 * Es lo que permite resolver el tenant **antes** de consultar `users`, que es
 * una tabla del tenant: sin el prefijo haría falta un `UNIQUE` global y una
 * cuarta excepción en `GlobalUniqueExceptions`.
 */
it('no reconoce como organización lo que no es un número', function (string $token): void {
    expect(TeamInvitation::organizationIdIn($token))->toBeNull();
})->with([
    'sin separador' => ['a8f3c1'],
    'prefijo no numérico' => ['ferreteria.a8f3c1'],
    'vacío' => [''],
    'solo separador' => ['.a8f3c1'],
]);

it('guarda el hash y nunca el texto en claro', function (): void {
    $organization = new Organization;
    $organization->forceFill(['id' => 1]);

    $invitation = TeamInvitation::issueFor($organization);

    expect($invitation->tokenHash)
        ->toBe(hash('sha256', $invitation->plainToken))
        ->not->toBe($invitation->plainToken)
        ->toHaveLength(64);
});

/**
 * RB-4 · 48 horas, y la cifra vive en la clase para que este test la cite en
 * vez de repetirla.
 */
it('vive exactamente el plazo declarado', function (): void {
    $organization = new Organization;
    $organization->forceFill(['id' => 1]);

    // El reloj se congela: el plazo es exacto y una diferencia de
    // milisegundos entre la emisión y la aserción no es lo que se mide.
    Carbon::setTestNow('2026-09-14 10:00:00');

    try {
        $invitation = TeamInvitation::issueFor($organization);

        expect(TeamInvitation::TTL_HOURS)->toBe(48);
        expect($invitation->expiresAt->toDateTimeString())->toBe('2026-09-16 10:00:00');
    } finally {
        Carbon::setTestNow();
    }
});

/**
 * Dos emisiones seguidas no se parecen en nada salvo en el prefijo: el secreto
 * es aleatorio y el reenvío tiene que invalidar de verdad al anterior (SP-6).
 */
it('emite un secreto distinto cada vez', function (): void {
    $organization = new Organization;
    $organization->forceFill(['id' => 7]);

    $first = TeamInvitation::issueFor($organization);
    $second = TeamInvitation::issueFor($organization);

    expect($first->plainToken)->not->toBe($second->plainToken);
    expect($first->tokenHash)->not->toBe($second->tokenHash);
});
