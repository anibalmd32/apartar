<?php

declare(strict_types=1);

use App\Actions\Product\SpecificationInput;

it('recorta y colapsa los espacios de la sección y la característica', function (): void {
    $row = SpecificationInput::of('  Motor  principal ', '  Potencia   máxima ', ' 750 W ');

    expect($row->group)->toBe('Motor principal');
    expect($row->label)->toBe('Potencia máxima');
    expect($row->value)->toBe('750 W');
});

it('convierte la sección vacía en ninguna', function (?string $group): void {
    expect(SpecificationInput::of($group, 'Material', 'Acero')->group)->toBeNull();
})->with([
    'ausente' => [null],
    'vacía' => [''],
    'solo espacios' => ['   '],
]);

it('guarda el valor tal cual, sin escapar nada', function (string $value): void {
    expect(SpecificationInput::of(null, 'Peso', $value)->value)->toBe($value);
})->with([
    'una medida con menor que' => ['< 2 kg'],
    'una etiqueta HTML' => ['<script>alert("xss")</script>'],
    'un ampersand' => ['Acero & aluminio'],
]);

it('conserva los saltos de línea del valor', function (): void {
    expect(SpecificationInput::of(null, 'Medidas', "Alto: 120 cm\nAncho: 80 cm")->value)
        ->toBe("Alto: 120 cm\nAncho: 80 cm");
});

it('rechaza una fila sin característica', function (string $label): void {
    SpecificationInput::of('Motor', $label, '750 W');
})->with([
    'vacía' => [''],
    'solo espacios' => ['   '],
])->throws(InvalidArgumentException::class, 'necesita su característica');

it('compone los atributos de la fila con su posición', function (): void {
    expect(SpecificationInput::of('Motor', 'Potencia', '750 W')->toAttributes(7, 3))->toBe([
        'product_id' => 7,
        'group' => 'Motor',
        'label' => 'Potencia',
        'value' => '750 W',
        'position' => 3,
    ]);
});
