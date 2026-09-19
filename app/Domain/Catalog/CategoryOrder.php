<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Category;

/**
 * El orden manual de las categorías de una organización (`REQ-BOF-023` RB-2).
 *
 * Es el **único** sitio que escribe `position`, y esa concentración es lo que
 * hace verdadera la invariante: después de crear, de borrar o de arrastrar,
 * las posiciones son `1..n` **sin huecos**. Un hueco no se ve en la pantalla
 * —la lista se pinta ordenada igual— pero el siguiente arrastre calcula sobre
 * una numeración que no coincide con lo que la persona está viendo, y el
 * resultado es un orden que «salta» sin motivo aparente.
 *
 * ## Reescribe la lista entera, y por eso no hay conflicto que resolver
 *
 * `apply()` no mueve una fila: renumera todas. Dos personas arrastrando la
 * misma lista a la vez **no tienen un conflicto**, tienen un orden que acaba
 * siendo el de quien soltó después (SP-5), y montar bloqueo optimista para
 * esto significaría rechazarle el guardado a alguien que no hizo nada malo.
 *
 * ## Lo que no venga en la lista no se pierde: se va al final
 *
 * La pantalla manda los identificadores que tenía cargados. Si entre la carga
 * y el guardado alguien creó una categoría, esa no viene en la lista — y
 * dejarla fuera la borraría del orden, es decir, le daría `position` de
 * ninguna parte. Va al final, conservando su orden relativo actual.
 *
 * Y lo que venga y no sea de esta organización simplemente **no existe**: la
 * lista de partida sale de `Category::query()`, con el *global scope* puesto,
 * así que un identificador ajeno no aparece en ella y no se numera
 * (`REQ-ORG-002` RB-1).
 */
final class CategoryOrder
{
    /**
     * Renumera las categorías de la organización activa a `1..n`.
     *
     * @param  list<int>  $orderedIds  el orden pedido; lo que falte va al final
     */
    public function apply(array $orderedIds = []): void
    {
        $current = $this->currentOrder();

        $requested = [];

        foreach ($orderedIds as $id) {
            // SP-5 y la deduplicación: una lista con el mismo identificador
            // dos veces es un arrastre mal serializado, no una intención.
            // Gana su primera aparición.
            if (in_array($id, $current, true) && ! in_array($id, $requested, true)) {
                $requested[] = $id;
            }
        }

        $rest = array_values(array_diff($current, $requested));

        $position = 1;

        foreach ([...$requested, ...$rest] as $id) {
            Category::query()->whereKey($id)->update(['position' => $position]);
            $position++;
        }
    }

    /**
     * La posición que le toca a una categoría nueva: **la última**.
     *
     * Quien añade una categoría no está reordenando su lista, la está
     * ampliando: colocarla arriba movería de sitio las que lleva meses viendo
     * en su catálogo. Va aparte de `apply()` porque hay que saberla **antes**
     * del `INSERT` —una fila con la posición por defecto se colaría delante de
     * todas al renumerar, que es exactamente lo contrario de lo que se quiere—.
     *
     * El `max` va por el global scope, así que cuenta las de esta organización
     * y solo las de esta organización (`REQ-ORG-002` RB-1).
     */
    public function next(): int
    {
        return (int) Category::query()->max('position') + 1;
    }

    /**
     * Las categorías de la organización activa, en el orden que tienen ahora.
     *
     * `position` manda y el identificador desempata, que es lo que hace que el
     * resultado sea estable: sin el segundo criterio, dos categorías que
     * compartieran posición —justo lo que esta clase existe para arreglar—
     * saldrían en el orden que quisiera MySQL y la renumeración daría un
     * resultado distinto en cada pasada.
     *
     * @return list<int>
     */
    private function currentOrder(): array
    {
        return Category::query()
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
