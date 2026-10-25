<?php

declare(strict_types=1);

use App\Domain\Auditing\AuditProperties;

it('guarda solo los campos que cambiaron, con su antes y su después', function (): void {
    $properties = AuditProperties::between(
        ['name' => 'Ferretería', 'phone' => '0212-5550000'],
        ['name' => 'Ferretería El Tornillo', 'phone' => '0212-5550000'],
    );

    expect($properties->toArray())->toBe([
        'changed' => ['name' => ['before' => 'Ferretería', 'after' => 'Ferretería El Tornillo']],
    ]);
});

it('deja el diff vacío cuando no cambió nada', function (): void {
    $properties = AuditProperties::between(['name' => 'Igual'], ['name' => 'Igual']);

    expect($properties->isEmpty())->toBeTrue()
        ->and($properties->toArray())->toBe(['changed' => []]);
});

it('trata la aparición y la desaparición de un campo como un cambio', function (): void {
    expect(AuditProperties::between([], ['document' => 'J-123'])->toArray()['changed'])
        ->toBe(['document' => ['before' => null, 'after' => 'J-123']]);

    expect(AuditProperties::between(['document' => 'J-123'], [])->toArray()['changed'])
        ->toBe(['document' => ['before' => 'J-123', 'after' => null]]);
});

it('redacta las claves prohibidas', function (string $key): void {
    $changed = AuditProperties::between([$key => 'viejo'], [$key => 'nuevo'])->toArray()['changed'];

    expect($changed[$key])->toBe(['before' => AuditProperties::REDACTED, 'after' => AuditProperties::REDACTED]);
})->with([
    'password',
    'password_hash',
    'invitation_token_hash',
    'api_key',
    'account_id',
    'accountId',
    'related_account_id',
    'client_secret',
]);

it('deja constancia de que el campo redactado cambió', function (): void {
    $changed = AuditProperties::between(['password' => 'a'], ['password' => 'b'])->toArray()['changed'];

    expect($changed)->toHaveKey('password');
});

it('distingue un campo vacío de uno redactado', function (): void {
    $changed = AuditProperties::between(['api_token' => null], ['api_token' => 'algo'])->toArray()['changed'];

    expect($changed['api_token']['before'])->toBeNull()
        ->and($changed['api_token']['after'])->toBe(AuditProperties::REDACTED);
});

it('recorta los textos largos', function (): void {
    $changed = AuditProperties::between(
        ['instructions' => ''],
        ['instructions' => str_repeat('a', 900)],
    )->toArray()['changed'];

    expect(mb_strlen((string) $changed['instructions']['after']))->toBeLessThan(900);
});

it('guarda entero un motivo de 500 caracteres', function (): void {
    $reason = str_repeat('ñ', App\Domain\Layaway\StaffReason::MAX);

    $changed = AuditProperties::between([], ['reason' => $reason])->toArray()['changed'];

    expect($changed['reason']['after'])->toBe($reason);
});

it('cambia el detalle por su resumen cuando no cabe', function (): void {
    $before = [];
    $after = [];

    foreach (range(1, 120) as $index) {
        $before['campo_'.$index] = str_repeat('x', 300);
        $after['campo_'.$index] = str_repeat('y', 300);
    }

    $properties = AuditProperties::between($before, $after)->toArray();

    expect($properties['truncated'])->toBeTrue()
        ->and($properties['changed'])->toHaveKey('campo_1')
        ->and($properties['changed']['campo_1'])->toBe(['before' => '…', 'after' => '…']);
});

it('acota las listas largas', function (): void {
    $changed = AuditProperties::between(
        ['cuotas' => []],
        ['cuotas' => range(1, 50)],
    )->toArray()['changed'];

    expect($changed['cuotas']['after'])->toHaveCount(21)->toContain('…');
});

it('reduce a algo serializable lo que no lo era', function (): void {
    $changed = AuditProperties::between(
        ['estado' => null, 'objeto' => null],
        ['estado' => App\Domain\Tenancy\OrganizationRole::Owner, 'objeto' => new stdClass],
    )->toArray()['changed'];

    expect($changed['estado']['after'])->toBe('owner')
        ->and($changed['objeto']['after'])->toBe('[stdClass]');
});

it('redacta también dentro de una estructura anidada', function (): void {
    $changed = AuditProperties::between(
        ['cliente' => []],
        ['cliente' => ['name' => 'Ana', 'account_id' => 42]],
    )->toArray()['changed'];

    expect($changed['cliente']['after'])->toBe(['name' => 'Ana', 'account_id' => AuditProperties::REDACTED]);
});
