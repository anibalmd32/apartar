<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El orden que llega tras soltar un arrastre (`REQ-BOF-023` RB-2, SP-5).
 *
 * **No comprueba que los identificadores existan ni que sean de esta
 * organización**, y es deliberado: lo hace `CategoryOrder`, que parte de la
 * lista real del tenant y numera lo que encuentra. Validarlo aquí con un
 * `exists` obligaría a nombrar `organization_id` en un `FormRequest` —la línea
 * que `tenancy:audit` existe para que nadie escriba— y además convertiría en
 * `422` un caso que no es un error: entre cargar la pantalla y soltar el
 * arrastre, alguien pudo borrar una categoría, y rechazar el guardado entero
 * por eso sería perderle el trabajo a quien no hizo nada mal.
 */
final class ReorderCategoriesRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // `present` y no `required`: una lista vacía es una petición
            // válida —significa «no cambies el orden»— y `required` la
            // rechazaría por parecer ausente.
            'categories' => ['present', 'array'],
            'categories.*' => ['integer'],
        ];
    }

    /**
     * Los identificadores en el orden pedido.
     *
     * @return list<int>
     */
    public function orderedIds(): array
    {
        $ids = $this->input('categories', []);

        // La validación ya garantiza el array; la guarda existe porque
        // `input()` devuelve `mixed` y un `array_map` sobre otra cosa sería un
        // error de tipos en tiempo de ejecución en lugar de una lista vacía.
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_map(intval(...), $ids));
    }
}
