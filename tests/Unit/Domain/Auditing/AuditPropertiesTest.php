<?php

declare(strict_types=1);

use App\Domain\Auditing\AuditProperties;

/**
 * El `properties` de una entrada: el antes y el después, sin nada que no deba
 * estar ahí (`REQ-BOF-005` RB-2, RB-5, SP-2).
 *
 * De las tres reglas que se comprueban aquí, **la de RB-5 es la que de verdad
 * protege algo**: un `account_id` dentro de la auditoría de una organización le
 * entrega a un negocio la identidad global de un consumidor, que es una fuga
 * entre las dos identidades del diseño (`D-8`) y no un descuido de formato.
 */

/**
 * RB-2 · solo lo que cambió, con su par.
 */
it('guarda solo los campos que cambiaron, con su antes y su después', function (): void {
    $properties = AuditProperties::between(
        ['name' => 'Ferretería', 'phone' => '0212-5550000'],
        ['name' => 'Ferretería El Tornillo', 'phone' => '0212-5550000'],
    );

    expect($properties->toArray())->toBe([
        'changed' => ['name' => ['before' => 'Ferretería', 'after' => 'Ferretería El Tornillo']],
    ]);
});

/**
 * Una operación que no cambió nada deja el diff vacío, y eso también es
 * información: dice que alguien guardó el formulario sin tocar nada.
 */
it('deja el diff vacío cuando no cambió nada', function (): void {
    $properties = AuditProperties::between(['name' => 'Igual'], ['name' => 'Igual']);

    expect($properties->isEmpty())->toBeTrue()
        ->and($properties->toArray())->toBe(['changed' => []]);
});

/**
 * Un campo que aparece o desaparece cuenta como cambio, con `null` del otro
 * lado: «se puso un RIF» y «se quitó» son dos hechos distintos y los dos
 * importan.
 */
it('trata la aparición y la desaparición de un campo como un cambio', function (): void {
    expect(AuditProperties::between([], ['document' => 'J-123'])->toArray()['changed'])
        ->toBe(['document' => ['before' => null, 'after' => 'J-123']]);

    expect(AuditProperties::between(['document' => 'J-123'], [])->toArray()['changed'])
        ->toBe(['document' => ['before' => 'J-123', 'after' => null]]);
});

/**
 * **RB-5 · lo que nunca entra.** Contraseñas, huellas, tokens y `account_id`.
 */
it('redacta las claves prohibidas por RB-5', function (string $key): void {
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

/**
 * Redactar y **no omitir**: la clave sigue en el diff diciendo que algo cambió.
 * Omitirla haría indistinguible «no cambió» de «no te lo puedo contar», y la
 * primera es una respuesta y la segunda un agujero.
 */
it('deja constancia de que el campo redactado cambió', function (): void {
    $changed = AuditProperties::between(['password' => 'a'], ['password' => 'b'])->toArray()['changed'];

    expect($changed)->toHaveKey('password');
});

/**
 * Y un nulo redactado sigue siendo nulo: si no había valor, decir
 * `[redactado]` inventaría uno.
 */
it('distingue un campo vacío de uno redactado', function (): void {
    $changed = AuditProperties::between(['api_token' => null], ['api_token' => 'algo'])->toArray()['changed'];

    expect($changed['api_token']['before'])->toBeNull()
        ->and($changed['api_token']['after'])->toBe(AuditProperties::REDACTED);
});

/**
 * SP-2 · un texto largo se recorta en lugar de viajar entero.
 */
it('recorta los textos largos', function (): void {
    $changed = AuditProperties::between(
        ['instructions' => ''],
        ['instructions' => str_repeat('a', 900)],
    )->toArray()['changed'];

    expect(mb_strlen((string) $changed['instructions']['after']))->toBeLessThan(900);
});

/**
 * **SP-2 · y cuando ni recortando cabe, se guarda el resumen.**
 *
 * El resumen conserva los **nombres** de los campos y tira los valores: ante la
 * pregunta «¿qué tocó esta operación?», saber que fueron el precio y la
 * frecuencia vale mucho más que un JSON que nadie abre. Y dice que es un
 * resumen, porque un `properties` recortado en silencio se leería como «solo
 * cambió esto».
 */
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

/**
 * Una lista se acota y se marca: 36 cuotas reasignadas no caben en una fila que
 * alguien vaya a leer.
 */
it('acota las listas largas', function (): void {
    $changed = AuditProperties::between(
        ['cuotas' => []],
        ['cuotas' => range(1, 50)],
    )->toArray()['changed'];

    expect($changed['cuotas']['after'])->toHaveCount(21)->toContain('…');
});

/**
 * Un enum se guarda por su valor, que es lo que significa; un objeto sin
 * representación de texto, por su tipo. Lo que **no** puede pasar es que un
 * valor no serializable tumbe la operación (RB-4), y por eso ninguno de estos
 * casos lanza.
 */
it('reduce a algo serializable lo que no lo era', function (): void {
    $changed = AuditProperties::between(
        ['estado' => null, 'objeto' => null],
        ['estado' => App\Domain\Tenancy\OrganizationRole::Owner, 'objeto' => new stdClass],
    )->toArray()['changed'];

    expect($changed['estado']['after'])->toBe('owner')
        ->and($changed['objeto']['after'])->toBe('[stdClass]');
});

/**
 * Las claves anidadas también se redactan: un `account_id` dentro de una lista
 * es la misma fuga con un nivel más.
 */
it('redacta también dentro de una estructura anidada', function (): void {
    $changed = AuditProperties::between(
        ['cliente' => []],
        ['cliente' => ['name' => 'Ana', 'account_id' => 42]],
    )->toArray()['changed'];

    expect($changed['cliente']['after'])->toBe(['name' => 'Ana', 'account_id' => AuditProperties::REDACTED]);
});
