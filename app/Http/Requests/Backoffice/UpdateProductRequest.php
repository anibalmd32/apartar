<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Product\ProductData;

/**
 * Los datos de un producto que ya existe (`REQ-BOF-020`).
 *
 * Extiende el del alta porque los once campos, sus reglas y su traducción a
 * `ProductData` son los mismos. Lo único que cambia es el `slug`, y ese cambio
 * **es** una regla del requisito:
 *
 *  - al crear no se acepta, porque se autogenera del nombre (RB-3);
 *  - al editar se acepta y es opcional, y **ausente significa «no lo toques»**.
 *    Eso es lo que hace verdadero que renombrar no arrastre el enlace: el
 *    formulario que solo manda el nombre no puede romper los enlaces ya
 *    compartidos ni por accidente.
 *
 * Quién puede cambiarlo lo decide `UpsertProductAction`, no este archivo: SP-5
 * lo cierra **en cuanto el producto está publicado**, y eso depende de una fila
 * que el `FormRequest` tendría que ir a buscar por su cuenta.
 */
final class UpdateProductRequest extends StoreProductRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'slug' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function toData(): ProductData
    {
        return $this->productData($this->requestedSlug());
    }

    protected function requestedSlug(): ?string
    {
        return $this->nullableString('slug');
    }
}
