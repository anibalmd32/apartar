<?php

declare(strict_types=1);

use App\Domain\Tenancy\TeamInvitation;
use App\Models\Organization;
use Illuminate\Support\Carbon;

it('emite un token con el identificador de su organización delante', function (): void {
    $organization = new Organization;
    $organization->forceFill(['id' => 42]);

    $invitation = TeamInvitation::issueFor($organization);

    expect($invitation->plainToken)->toStartWith('42.');
    expect(TeamInvitation::organizationIdIn($invitation->plainToken))->toBe(42);
});

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

it('vive exactamente el plazo declarado', function (): void {
    $organization = new Organization;
    $organization->forceFill(['id' => 1]);

    Carbon::setTestNow('2026-09-14 10:00:00');

    try {
        $invitation = TeamInvitation::issueFor($organization);

        expect(TeamInvitation::TTL_HOURS)->toBe(48);
        expect($invitation->expiresAt->toDateTimeString())->toBe('2026-09-16 10:00:00');
    } finally {
        Carbon::setTestNow();
    }
});

it('emite un secreto distinto cada vez', function (): void {
    $organization = new Organization;
    $organization->forceFill(['id' => 7]);

    $first = TeamInvitation::issueFor($organization);
    $second = TeamInvitation::issueFor($organization);

    expect($first->plainToken)->not->toBe($second->plainToken);
    expect($first->tokenHash)->not->toBe($second->tokenHash);
});
