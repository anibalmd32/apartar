<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La composición de un catálogo tal como la manda la pantalla
 * (`REQ-CAT-023` RB-2, RB-8).
 *
 * **La lista entera, en su orden, y nada más.** No viaja `position`: el
 * servidor la recalcula contigua desde 1 en cada guardado (RB-2), y mandar un
 * número que el servidor va a reescribir sería una segunda verdad sobre el
 * orden — la que se queda vieja es siempre la del formulario. Es el mismo
 * contrato que `ReorderCategoriesRequest` y que el orden de las imágenes, y es
 * lo que hace que el gesto —botones ayer, arrastre hoy— sea sustituible sin
 * tocar el servidor.
 *
 * ## Aquí se valida la forma; el contenido es de la `Action`
 *
 * Que cada identificador sea un entero se comprueba aquí. Que ese entero sea
 * **un producto de esta organización** no, y no por comodidad: la respuesta
 * sale de una consulta con el *global scope* puesto, y escribir esa regla como
 * un `exists` de validación pediría una consulta sin filtrar o un `where`
 * a mano de `organization_id` — que es justo lo que `tenancy:audit` existe
 * para que nadie escriba. La guarda vive en `SyncCatalogProductsAction`, que
 * es donde `REQ-ORG-002` RB-7 la pone.
 */
final class SyncCatalogProductsRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // `present` y no `required`: la lista vacía es una petición válida
            // —vaciar un catálogo en borrador lo es (SP-7)— y `required` la
            // rechazaría por parecer ausente, con un mensaje que hablaría de
            // un campo que sí llegó.
            'products' => ['present', 'array'],
            'products.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['products.*.integer' => 'La lista de productos llegó mal formada.'];
    }

    /**
     * Los identificadores en el orden pedido.
     *
     * @return list<int>
     */
    public function orderedIds(): array
    {
        $ids = $this->input('products', []);

        // La validación ya garantiza el array; la guarda existe porque
        // `input()` devuelve `mixed` y un `array_map` sobre otra cosa sería un
        // error de tipos en ejecución en lugar de una lista vacía.
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_map(intval(...), $ids));
    }
}
