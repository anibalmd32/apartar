<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A qué categoría se mueven los productos antes de borrar la actual
 * (`REQ-BOF-023` RB-4).
 *
 * Una sola regla que merezca comentario: **el destino no puede ser la misma
 * categoría**. Es una petición sin efecto, y aceptarla en silencio dejaría a
 * quien la hizo creyendo que ya puede borrar — y encontrándose el mismo `422`
 * un segundo después, sin nada que explique por qué.
 *
 * Que el destino exista y sea de esta organización **no se comprueba aquí**:
 * lo resuelve la acción con `Category::query()->findOrFail()`, que lleva el
 * global scope puesto y responde `404` para la categoría ajena (SP-7). Un
 * `exists` en este archivo daría un `422` en su lugar, y un `422` sobre un
 * identificador ajeno confirma que existe.
 */
final class ReassignCategoryProductsRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                'integer',
                Rule::notIn(array_filter([$this->currentId()])),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.not_in' => 'Elige una categoría distinta de la que quieres borrar.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'category_id' => 'categoría de destino',
        ];
    }

    public function targetId(): int
    {
        return $this->integer('category_id');
    }

    /**
     * La categoría de la URL, que es la que se está vaciando.
     */
    private function currentId(): ?int
    {
        $category = $this->route('category');

        return $category instanceof Category ? (int) $category->getKey() : null;
    }
}
