<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\ProductSpecification;
use Illuminate\Support\Collection;

/**
 * Agrupa y ordena la ficha técnica para quien la lee (`REQ-CAT-004` RB-2,
 * RB-3, SP-2, SP-3).
 *
 * ## Vive aquí y no en un *resource*
 *
 * El gate de `REQ-CAT-004` nombra `ProductDetailResource::groupSpecifications()`,
 * y ese *resource* es de **`T-035`**. Escribir la agrupación dentro de una
 * clase que todavía no existe habría sido imposible; escribirla dos veces
 * —una provisional aquí y otra definitiva allí— es peor. Así que la regla vive
 * en el dominio, que es de donde `T-035` la va a llamar en una línea, y es el
 * mismo reparto que `T-022` hizo con `MoneyLabel`: la pieza y su prueba ahora,
 * la página que la monta en su tarea.
 *
 * Y además tiene dos llamantes, no uno: la ficha pública de `T-035` y el
 * autocompletado de grupos de `SpecificationGroupsQuery`, que necesita la
 * misma normalización para no sugerir «Motor» y «motor» como dos cosas.
 *
 * ## Las cuatro reglas del orden
 *
 *  - **`group = null` va primero** (RB-2). Se renderiza bajo el rótulo
 *    «General», y ese rótulo es de la pantalla: aquí el grupo sigue siendo
 *    `null`, que es lo que dice el contrato `SpecificationGroup` del módulo
 *    [11]. Traducirlo a la cadena «General» en el servidor haría indistinguible
 *    una ficha sin secciones de una que tiene una sección llamada así.
 *  - **Dentro de cada grupo, por `position` y a igualdad por `id`** (RB-3).
 *    El desempate no es decorativo: dos filas con la misma posición —lo que
 *    deja una migración de datos o un guardado a medias— saldrían en el orden
 *    que quisiera MySQL y la ficha bailaría entre recargas.
 *  - **Los grupos entre sí, por su primera aparición.** La spec no lo fija, y
 *    alfabético sería inventarse un criterio: el negocio ya ordenó sus filas
 *    arrastrándolas, así que el orden de los grupos es el de la primera fila
 *    de cada uno.
 *  - **La comparación es insensible a mayúsculas** (SP-2), y se muestra **la
 *    primera variante encontrada**: quien escribió «Motor» en tres filas y
 *    «motor» en la cuarta quiso decir lo mismo, y dos secciones idénticas a la
 *    vista serían un error de datos que nadie entiende.
 *
 * SP-3 · una fila con el `label` vacío **se descarta al renderizar**. El CRUD
 * ya lo bloquea (`REQ-BOF-021` SP-1); esto es la segunda red, para una fila
 * que entrara por otra puerta —una importación, una migración— y que en la
 * ficha se vería como una celda en blanco sin explicación.
 */
final class SpecificationGroups
{
    /**
     * La ficha agrupada, lista para pintar.
     *
     * @param  Collection<int, ProductSpecification>  $specifications
     * @return list<array{group: string|null, items: list<array{label: string, value: string}>}>
     */
    public static function of(Collection $specifications): array
    {
        /** @var array<string, array{group: string|null, items: list<array{label: string, value: string}>}> $groups */
        $groups = [];

        foreach (self::sorted($specifications) as $specification) {
            if (trim($specification->label) === '') {
                continue;
            }

            $key = self::keyFor($specification->group);

            // La primera variante gana: la sección se llama como se llamó la
            // primera vez que alguien la escribió.
            $groups[$key] ??= ['group' => $specification->group, 'items' => []];

            $groups[$key]['items'][] = [
                'label' => $specification->label,
                'value' => $specification->value,
            ];
        }

        return self::withGeneralFirst($groups);
    }

    /**
     * Clave con la que dos grupos se consideran el mismo (SP-2).
     *
     * `null` tiene su propia clave y no se confunde con la cadena vacía: una
     * fila sin sección y una sección que se llama nada acabarían juntas, y la
     * segunda no debería existir —`SpecificationInput` ya la convierte en
     * `null` al guardar—.
     */
    public static function keyFor(?string $group): string
    {
        return $group === null ? '' : mb_strtolower(trim($group));
    }

    /**
     * Las filas por `position` y, a igualdad, por `id` (RB-3).
     *
     * Con la forma de dos claves de `sortBy()` —`[['columna', 'asc'], …]`— y
     * **no** con un array de dos *closures*: esa otra forma se lee igual de
     * bien y ordena por otra cosa, porque la colección la interpreta como una
     * sola comparación con dirección en vez de como dos criterios.
     *
     * @param  Collection<int, ProductSpecification>  $specifications
     * @return list<ProductSpecification>
     */
    private static function sorted(Collection $specifications): array
    {
        return $specifications
            ->sortBy([['position', 'asc'], ['id', 'asc']])
            ->values()
            ->all();
    }

    /**
     * El grupo sin nombre delante, y el resto en el orden en que aparecieron
     * (RB-2).
     *
     * @param  array<string, array{group: string|null, items: list<array{label: string, value: string}>}>  $groups
     * @return list<array{group: string|null, items: list<array{label: string, value: string}>}>
     */
    private static function withGeneralFirst(array $groups): array
    {
        $general = $groups[''] ?? null;

        unset($groups['']);

        $ordered = array_values($groups);

        if ($general !== null) {
            array_unshift($ordered, $general);
        }

        return $ordered;
    }
}
