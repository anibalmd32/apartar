<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Catalog\SpecificationGroups;
use App\Models\ProductSpecification;

/**
 * Los grupos de ficha técnica que ya usa el negocio, para autocompletar
 * (`REQ-BOF-021` RB-6, SP-8).
 *
 * ## Es una fuga esperando a ocurrir, y por eso tiene su propia clase
 *
 * Un autocompletado que sugiera «Motor» porque lo usa la ferretería de al lado
 * no da un error: da una pista de que ese negocio existe y de cómo describe lo
 * que vende. SP-8 lo prohíbe expresamente y exige un test de fuga con dos
 * organizaciones.
 *
 * Lo que lo impide no es un `where` escrito aquí: es que la consulta va por
 * `ProductSpecification::query()`, con el *global scope* del tenant puesto
 * (`REQ-ORG-002` RB-1). Un `DB::table('product_specifications')` daría el
 * mismo resultado hoy y sería exactamente la línea que `tenancy:audit` existe
 * para que nadie escriba.
 *
 * ## Sugiere **todos** los grupos de la organización, incluidos los del propio
 * producto
 *
 * RB-6 dice «los grupos ya usados en otros productos». Excluir los del
 * producto que se está editando quitaría justamente las sugerencias más
 * útiles: quien acaba de escribir «Motor» en tres filas y añade la cuarta
 * espera que se le ofrezca. La frontera que importa es la de la organización,
 * y esa se respeta.
 *
 * ## Y deduplica como agrupa
 *
 * Con `SpecificationGroups::keyFor()`, que es la misma comparación insensible
 * a mayúsculas de `REQ-CAT-004` SP-2. Sugerir «Motor» y «motor» como dos
 * opciones distintas invitaría a crear justo el duplicado que la ficha luego
 * junta.
 */
final class SpecificationGroupsQuery
{
    /**
     * Los grupos de la organización activa, sin repetir y en orden alfabético.
     *
     * Alfabético y no por frecuencia: es una lista para buscar con la vista,
     * y un orden por uso cambiaría de sitio las opciones entre una carga y la
     * siguiente.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $groups = ProductSpecification::query()
            ->whereNotNull('group')
            ->orderBy('group')
            ->pluck('group')
            ->all();

        $unique = [];

        foreach ($groups as $group) {
            $group = trim((string) $group);

            if ($group === '') {
                continue;
            }

            // La primera variante gana, igual que al agrupar la ficha.
            $unique[SpecificationGroups::keyFor($group)] ??= $group;
        }

        return array_values($unique);
    }
}
