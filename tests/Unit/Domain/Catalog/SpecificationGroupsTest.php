<?php

declare(strict_types=1);

use App\Domain\Catalog\SpecificationGroups;
use App\Models\ProductSpecification;
use Illuminate\Support\Collection;

/**
 * @param  list<array{group?: string|null, label?: string, value?: string, position?: int, id?: int}>  $rows
 * @return Collection<int, ProductSpecification>
 */
function specifications(array $rows): Collection
{
    $specifications = [];

    foreach ($rows as $index => $row) {
        $specification = new ProductSpecification;

        $specification->forceFill([
            'id' => $row['id'] ?? $index + 1,
            'group' => $row['group'] ?? null,
            'label' => $row['label'] ?? 'Característica',
            'value' => $row['value'] ?? 'Valor',
            'position' => $row['position'] ?? $index + 1,
        ]);

        $specifications[] = $specification;
    }

    return new Collection($specifications);
}

it('devuelve una lista vacía cuando no hay especificaciones', function (): void {
    expect(SpecificationGroups::of(specifications([])))->toBe([]);
});

it('pone primero el grupo sin nombre y lo deja en null', function (): void {
    $groups = SpecificationGroups::of(specifications([
        ['group' => 'Motor', 'label' => 'Potencia', 'position' => 1],
        ['group' => null, 'label' => 'Material', 'position' => 2],
    ]));

    expect($groups)->toHaveCount(2);
    expect($groups[0]['group'])->toBeNull();
    expect($groups[0]['items'][0]['label'])->toBe('Material');
    expect($groups[1]['group'])->toBe('Motor');
});

it('ordena los grupos por la primera fila de cada uno', function (): void {
    $groups = SpecificationGroups::of(specifications([
        ['group' => 'Zumbador', 'label' => 'Decibelios', 'position' => 1],
        ['group' => 'Motor', 'label' => 'Potencia', 'position' => 2],
        ['group' => 'Zumbador', 'label' => 'Tono', 'position' => 3],
    ]));

    expect(array_column($groups, 'group'))->toBe(['Zumbador', 'Motor']);
    expect($groups[0]['items'])->toHaveCount(2);
});

it('ordena las filas de un grupo por su posición', function (): void {
    $groups = SpecificationGroups::of(specifications([
        ['label' => 'Tercera', 'position' => 3],
        ['label' => 'Primera', 'position' => 1],
        ['label' => 'Segunda', 'position' => 2],
    ]));

    expect(array_column($groups[0]['items'], 'label'))->toBe(['Primera', 'Segunda', 'Tercera']);
});

it('desempata dos filas de la misma posición por su identificador', function (): void {
    $groups = SpecificationGroups::of(specifications([
        ['id' => 9, 'label' => 'La nueve', 'position' => 1],
        ['id' => 4, 'label' => 'La cuatro', 'position' => 1],
    ]));

    expect(array_column($groups[0]['items'], 'label'))->toBe(['La cuatro', 'La nueve']);
});

it('agrupa sin distinguir mayúsculas y conserva la primera grafía', function (): void {
    $groups = SpecificationGroups::of(specifications([
        ['group' => 'Motor', 'label' => 'Potencia', 'position' => 1],
        ['group' => 'motor', 'label' => 'Velocidades', 'position' => 2],
        ['group' => '  MOTOR  ', 'label' => 'Revoluciones', 'position' => 3],
    ]));

    expect($groups)->toHaveCount(1);
    expect($groups[0]['group'])->toBe('Motor');
    expect($groups[0]['items'])->toHaveCount(3);
});

it('descarta las filas sin característica', function (): void {
    $groups = SpecificationGroups::of(specifications([
        ['label' => '', 'position' => 1],
        ['label' => '   ', 'position' => 2],
        ['label' => 'Material', 'position' => 3],
    ]));

    expect($groups)->toHaveCount(1);
    expect($groups[0]['items'])->toBe([['label' => 'Material', 'value' => 'Valor']]);
});

it('no deja grupos vacíos cuando se descartan todas sus filas', function (): void {
    expect(SpecificationGroups::of(specifications([['group' => 'Motor', 'label' => '']])))->toBe([]);
});

it('no toca el contenido del valor', function (): void {
    $attack = '<script>alert("xss")</script>';

    $groups = SpecificationGroups::of(specifications([['label' => 'Nota', 'value' => $attack]]));

    expect($groups[0]['items'][0]['value'])->toBe($attack);
});

it('da claves distintas a la ausencia de grupo y a la cadena vacía', function (): void {
    expect(SpecificationGroups::keyFor(null))->toBe('');
    expect(SpecificationGroups::keyFor('Motor'))->toBe('motor');
    expect(SpecificationGroups::keyFor('  MOTOR '))->toBe('motor');
});
