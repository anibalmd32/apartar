<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Models\Category;
use App\Rules\UniqueTaxonomyName;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Los datos de una categoría que llegan del formulario (`REQ-BOF-023` RB-3,
 * SP-2).
 *
 * **El nombre duplicado se rechaza aquí y no en la acción**, al revés que el
 * slug bloqueado: un nombre repetido lo corrige la persona que lo está
 * escribiendo, y para eso el error tiene que llegar junto a su campo. El slug
 * bloqueado, en cambio, no se corrige escribiendo otra cosa —está cerrado— y
 * depende de un hecho del inventario, así que lo decide la acción.
 *
 * **`slug` es opcional en los dos caminos y significa cosas distintas.** Al
 * crear, ausente quiere decir «genéralo del nombre» (RB-3). Al editar, ausente
 * quiere decir «no lo toques», que es lo que hace verdadero RB-6: renombrar no
 * arrastra el enlace, y el formulario que solo manda `name` no puede romper
 * los filtros ya compartidos ni por accidente.
 *
 * El permiso no se comprueba en este archivo: lo hace la policy dentro de la
 * acción (`REQ-ORG-003` RB-4).
 */
final class UpsertCategoryRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // 120 es el tope de la columna, y el del requisito a la vez: es
            // una etiqueta de un filtro, no un campo de texto.
            'name' => ['required', 'string', 'max:120', new UniqueTaxonomyName(Category::class, $this->editedId())],

            'slug' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'slug' => 'enlace',
        ];
    }

    /**
     * El nombre, sin los espacios con los que nadie quiso decir nada.
     */
    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    /**
     * El enlace pedido, o `null` si no se pidió ninguno.
     *
     * Vacío y ausente significan lo mismo: «no lo toques» al editar y
     * «genéralo» al crear. Un `''` guardado sería una categoría sin URL
     * alcanzable.
     */
    public function slug(): ?string
    {
        $slug = trim((string) $this->string('slug'));

        return $slug === '' ? null : $slug;
    }

    /**
     * La categoría que se está editando, para que su propio nombre no choque
     * consigo mismo.
     */
    private function editedId(): ?int
    {
        $category = $this->route('category');

        return $category instanceof Category ? (int) $category->getKey() : null;
    }
}
