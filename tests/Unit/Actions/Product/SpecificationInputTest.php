<?php

declare(strict_types=1);

use App\Actions\Product\SpecificationInput;

/**
 * Una fila de ficha técnica (`REQ-BOF-021`).
 *
 * Lo que merece prueba es **qué normaliza y qué no**: los espacios sí, el
 * contenido no. La segunda mitad es la que se hace mal —sanear al guardar
 * convierte «< 2 kg» en otra cosa— y por eso tiene su propio caso.
 */
it('recorta y colapsa los espacios de la sección y la característica', function (): void {
    $row = SpecificationInput::of('  Motor  principal ', '  Potencia   máxima ', ' 750 W ');

    expect($row->group)->toBe('Motor principal');
    expect($row->label)->toBe('Potencia máxima');
    expect($row->value)->toBe('750 W');
});

/**
 * «Sin sección» es un estado y una cadena vacía es una sección que se llama
 * nada: en la ficha se pintaría como un encabezado en blanco.
 */
it('convierte la sección vacía en ninguna', function (?string $group): void {
    expect(SpecificationInput::of($group, 'Material', 'Acero')->group)->toBeNull();
})->with([
    'ausente' => [null],
    'vacía' => [''],
    'solo espacios' => ['   '],
]);

/**
 * **No sanea el valor**, y es lo contrario de lo que el instinto pide: el
 * escapado es de quien renderiza (`REQ-CAT-004` RB-4). Guardar `&lt; 2 kg` le
 * enseñaría al negocio su propio texto convertido en otra cosa.
 */
it('guarda el valor tal cual, sin escapar nada', function (string $value): void {
    expect(SpecificationInput::of(null, 'Peso', $value)->value)->toBe($value);
})->with([
    'una medida con menor que' => ['< 2 kg'],
    'una etiqueta HTML' => ['<script>alert("xss")</script>'],
    'un ampersand' => ['Acero & aluminio'],
]);

/**
 * `REQ-CAT-004` SP-1 · el valor largo conserva sus saltos de línea: recortarlo
 * sería decidir por el negocio dónde acaba la descripción de lo que vende.
 */
it('conserva los saltos de línea del valor', function (): void {
    expect(SpecificationInput::of(null, 'Medidas', "Alto: 120 cm\nAncho: 80 cm")->value)
        ->toBe("Alto: 120 cm\nAncho: 80 cm");
});

/**
 * La precondición: el `422` con el índice de la fila lo pone
 * `ValidatesSpecifications` (SP-1), y si esto lanza es que las filas se
 * construyeron sin pasar por la validación.
 */
it('rechaza una fila sin característica', function (string $label): void {
    SpecificationInput::of('Motor', $label, '750 W');
})->with([
    'vacía' => [''],
    'solo espacios' => ['   '],
])->throws(InvalidArgumentException::class, 'necesita su característica');

/**
 * Y los atributos con los que se escribe, con la posición que le toca: el
 * número lo decide `SyncSpecificationsAction` recorriendo la lista, no la
 * fila.
 */
it('compone los atributos de la fila con su posición', function (): void {
    expect(SpecificationInput::of('Motor', 'Potencia', '750 W')->toAttributes(7, 3))->toBe([
        'product_id' => 7,
        'group' => 'Motor',
        'label' => 'Potencia',
        'value' => '750 W',
        'position' => 3,
    ]);
});
