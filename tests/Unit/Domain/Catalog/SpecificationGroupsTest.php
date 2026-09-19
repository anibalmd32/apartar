<?php

declare(strict_types=1);

use App\Domain\Catalog\SpecificationGroups;
use App\Models\ProductSpecification;
use Illuminate\Support\Collection;

/**
 * La agrupación de la ficha técnica (`REQ-CAT-004` RB-2, RB-3, SP-2, SP-3).
 *
 * Es la pieza que `T-035` va a llamar desde `ProductDetailResource`, y se
 * prueba **sin base de datos**: las reglas son de ordenación y agrupación, no
 * de persistencia, y montarlas sobre modelos en memoria deja la matriz entera
 * —incluido el desempate por `id`— en un archivo que corre en milisegundos.
 */

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

/**
 * RB-2 · las filas sin sección van **primero**, y su grupo sigue siendo
 * `null`: el rótulo «General» lo pone la pantalla, para que una ficha sin
 * secciones no se confunda con una que tiene una sección llamada así.
 */
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

/**
 * Y los demás grupos, **por su primera aparición**. La spec no fija ese orden
 * y alfabético sería inventarse un criterio: el negocio ya ordenó sus filas
 * arrastrándolas.
 */
it('ordena los grupos por la primera fila de cada uno', function (): void {
    $groups = SpecificationGroups::of(specifications([
        ['group' => 'Zumbador', 'label' => 'Decibelios', 'position' => 1],
        ['group' => 'Motor', 'label' => 'Potencia', 'position' => 2],
        ['group' => 'Zumbador', 'label' => 'Tono', 'position' => 3],
    ]));

    expect(array_column($groups, 'group'))->toBe(['Zumbador', 'Motor']);
    expect($groups[0]['items'])->toHaveCount(2);
});

/**
 * RB-3 · dentro de cada grupo, por `position`.
 */
it('ordena las filas de un grupo por su posición', function (): void {
    $groups = SpecificationGroups::of(specifications([
        ['label' => 'Tercera', 'position' => 3],
        ['label' => 'Primera', 'position' => 1],
        ['label' => 'Segunda', 'position' => 2],
    ]));

    expect(array_column($groups[0]['items'], 'label'))->toBe(['Primera', 'Segunda', 'Tercera']);
});

/**
 * **Y a igualdad, por `id`.** El desempate no es decorativo: dos filas con la
 * misma posición —lo que deja una migración de datos o un guardado a medias—
 * saldrían en el orden que quisiera MySQL, y la ficha bailaría entre recargas
 * sin que nadie la tocara.
 */
it('desempata dos filas de la misma posición por su identificador', function (): void {
    $groups = SpecificationGroups::of(specifications([
        ['id' => 9, 'label' => 'La nueve', 'position' => 1],
        ['id' => 4, 'label' => 'La cuatro', 'position' => 1],
    ]));

    expect(array_column($groups[0]['items'], 'label'))->toBe(['La cuatro', 'La nueve']);
});

/**
 * SP-2 · «Motor» y «motor» son la misma sección, y se muestra **la primera
 * variante encontrada**: quien las escribió así quiso decir lo mismo, y dos
 * secciones idénticas a la vista serían un error de datos que nadie entiende.
 */
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

/**
 * SP-3 · una fila con la característica vacía **se descarta al renderizar**.
 * El CRUD ya la bloquea (`REQ-BOF-021` SP-1); esto es la segunda red, para una
 * que entrara por otra puerta y que en la ficha se vería como una celda en
 * blanco sin explicación.
 */
it('descarta las filas sin característica', function (): void {
    $groups = SpecificationGroups::of(specifications([
        ['label' => '', 'position' => 1],
        ['label' => '   ', 'position' => 2],
        ['label' => 'Material', 'position' => 3],
    ]));

    expect($groups)->toHaveCount(1);
    expect($groups[0]['items'])->toBe([['label' => 'Material', 'value' => 'Valor']]);
});

/**
 * Y si **todas** se descartan, no queda un grupo vacío: la ficha se omite
 * entera en vez de pintar un encabezado sin filas.
 */
it('no deja grupos vacíos cuando se descartan todas sus filas', function (): void {
    expect(SpecificationGroups::of(specifications([['group' => 'Motor', 'label' => '']])))->toBe([]);
});

/**
 * El valor sale **tal cual**: el escapado es de quien renderiza
 * (`REQ-CAT-004` RB-4), y sanear aquí guardaría la regla en el sitio
 * equivocado.
 */
it('no toca el contenido del valor', function (): void {
    $attack = '<script>alert("xss")</script>';

    $groups = SpecificationGroups::of(specifications([['label' => 'Nota', 'value' => $attack]]));

    expect($groups[0]['items'][0]['value'])->toBe($attack);
});

/**
 * La clave de agrupación distingue «sin sección» de una sección vacía: la
 * segunda no debería existir —`SpecificationInput` la convierte en `null`—
 * pero si existiera, juntarlas escondería el dato malo.
 */
it('da claves distintas a la ausencia de grupo y a la cadena vacía', function (): void {
    expect(SpecificationGroups::keyFor(null))->toBe('');
    expect(SpecificationGroups::keyFor('Motor'))->toBe('motor');
    expect(SpecificationGroups::keyFor('  MOTOR '))->toBe('motor');
});
