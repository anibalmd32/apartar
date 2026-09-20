<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use DateTimeInterface;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La fecha desde la cual se publica un producto
 * (`REQ-CAT-007` RB-5, SP-5).
 *
 * Un solo campo, opcional. Sin él, publicar es publicar ahora; con una fecha
 * **futura**, es publicación programada: el producto pasa a `published` y
 * quien lo mantiene invisible hasta la hora es `Product::scopePublished()`, en
 * la consulta y sin job (RB-5).
 *
 * Por qué la fecha entra por aquí y no por el formulario del producto:
 * `ProductData` no lleva `status` ni `published_at` —`T-027` los dejó fuera a
 * propósito— porque un alta que entregara el producto publicado escondería el
 * paso que tiene lista de requisitos. La programación es una forma de
 * publicar, así que viaja con la publicación.
 *
 * Una fecha **pasada** se admite y publica en el acto, que es lo mismo que no
 * mandar ninguna: `published_at <= now()` se cumple igual, y prohibirla
 * obligaría a distinguir dos casos que la consulta no distingue.
 */
final class PublishProductRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'published_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'published_at.date' => 'La fecha de publicación no se entiende.',
        ];
    }

    /**
     * Desde cuándo se publica, o `null` para «ahora».
     */
    public function publishedAt(): ?DateTimeInterface
    {
        return $this->date('published_at');
    }
}
