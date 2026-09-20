<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El orden de la galería y los textos alternativos que la acompañan
 * (`REQ-BOF-022` RB-2, RB-6, SP-8).
 *
 * **Que cada imagen sea de este producto no se comprueba aquí**: lo hace
 * `SyncImageOrderAction`, que es donde `REQ-ORG-002` RB-7 pone la guarda de
 * escritura. Un `exists` en este archivo daría un `422` sobre un identificador
 * ajeno, y un `422` sobre un identificador ajeno confirma que existe; además
 * `media` está declarada fuera del filtro por organización, así que la regla
 * tendría que nombrar el `organization_id` a mano — la línea que
 * `tenancy:audit` existe para que nadie escriba.
 *
 * El `alt` viaja con el orden porque es lo que la galería edita en la misma
 * pantalla y en el mismo guardado: partirlo en dos peticiones obligaría a una
 * cuarta ruta que el contrato de `REQ-BOF-022` no tiene.
 */
final class SyncImageOrderRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // `present` y no `required`: una lista vacía es una petición
            // válida —un producto sin fotos no tiene orden que guardar— y
            // `required` la rechazaría por parecer ausente.
            'images' => ['present', 'array'],
            'images.*.id' => ['required', 'integer'],

            // El tope es el de la columna de medialibrary y, sobre todo, el de
            // un texto que se lee en voz alta: un `alt` de párrafo no describe
            // una imagen, la narra.
            'images.*.alt' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'images' => 'imágenes',
            'images.*.alt' => 'texto alternativo',
        ];
    }

    /**
     * Las imágenes en el orden pedido, con su texto alternativo si viene.
     *
     * @return list<array{id: int, alt?: string|null}>
     */
    public function images(): array
    {
        $images = $this->input('images', []);

        if (! is_array($images)) {
            return [];
        }

        $ordered = [];

        foreach ($images as $image) {
            if (! is_array($image) || ! isset($image['id']) || ! is_numeric($image['id'])) {
                continue;
            }

            $entry = ['id' => (int) $image['id']];

            if (array_key_exists('alt', $image)) {
                $entry['alt'] = is_scalar($image['alt']) ? (string) $image['alt'] : null;
            }

            $ordered[] = $entry;
        }

        return $ordered;
    }
}
