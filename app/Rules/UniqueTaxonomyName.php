<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Comprueba que el nombre de una categoría o de una etiqueta no esté ya usado
 * **en esta organización** (`REQ-BOF-023` RB-3, SP-2).
 *
 * ## Por qué no es la regla `unique` del framework
 *
 * `Rule::unique('categories', 'name')` consulta con el *query builder* pelado,
 * que **no aplica el global scope** del tenant: comprobaría la tabla entera y
 * le diría a un negocio que «Ropa» está ocupada porque otro negocio tiene una
 * categoría con ese nombre. El arreglo evidente —añadirle
 * `->where('organization_id', …)`— es precisamente la línea que
 * `tenancy:audit` existe para que nadie escriba, y además reintroduce en un
 * `FormRequest` la decisión que `BelongsToOrganization` ya tomó.
 *
 * Preguntando por el modelo, el filtro viene puesto. Lo mismo que hace que
 * esta regla sea correcta es lo que hace que **el mismo nombre en dos
 * organizaciones esté permitido** sin escribir una línea para permitirlo
 * (RB-1).
 *
 * ## No sustituye al índice, lo acompaña
 *
 * La unicidad de verdad la garantiza `UNIQUE(organization_id, name)` en la
 * base, que es el único que aguanta dos peticiones simultáneas. Esta regla
 * existe para que el caso normal llegue como un `422` **junto a su campo** en
 * lugar de como un error de base de datos, y para que el mensaje diga qué
 * hacer. La carrera la resuelve `TaxonomyByName`, en el otro camino.
 *
 * La comparación la hace MySQL con la colación por defecto, que es
 * *case-insensitive*: «Ropa» y «ropa» chocan, igual que chocarían en el
 * índice. Comparar en PHP con `===` diría que no y dejaría pasar el duplicado
 * hasta la excepción de la base.
 */
final readonly class UniqueTaxonomyName implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model  `Category::class` o `Tag::class`
     * @param  int|null  $ignoreId  la fila que se está editando, que no choca consigo misma
     */
    public function __construct(
        private string $model,
        private ?int $ignoreId = null,
    ) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            // Que esté vacío lo dice `required` con su propio mensaje. Aquí
            // solo se comprueba la unicidad, y dos mensajes para el mismo
            // fallo se leen como dos problemas.
            return;
        }

        $query = $this->model::query()->where('name', trim($value));

        if ($this->ignoreId !== null) {
            $query->whereKeyNot($this->ignoreId);
        }

        if ($query->exists()) {
            $fail('Ya tienes algo con ese nombre. Usa el que ya existe o elige otro nombre.');
        }
    }
}
