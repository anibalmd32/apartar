<?php

declare(strict_types=1);

use App\Domain\Customer\LoginLinkToken;

/**
 * `REQ-IDN-003` RB-4: el prefijo dice la organización, y solo se guarda el
 * hash.
 */
it('lee la organización del prefijo y rechaza lo que no tiene la forma', function (string $token, ?int $organizationId): void {
    expect(LoginLinkToken::organizationIdIn($token))->toBe($organizationId);
})->with([
    'bien formado' => ['7.a8f3c0', 7],
    'sin separador' => ['a8f3c0', null],
    'prefijo no numérico' => ['x7.a8f3c0', null],
    'prefijo vacío' => ['.a8f3c0', null],
]);

it('guarda una huella SHA-256 que no contiene el secreto', function (): void {
    $hash = LoginLinkToken::hash('7.secreto');

    expect($hash)->toHaveLength(64)
        ->and($hash)->toBe(hash('sha256', '7.secreto'))
        ->and($hash)->not->toContain('secreto');
});

it('caduca en treinta minutos', function (): void {
    expect(LoginLinkToken::TTL_MINUTES)->toBe(30);
});
