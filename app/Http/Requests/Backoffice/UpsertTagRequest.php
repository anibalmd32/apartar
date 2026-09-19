<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Models\Tag;
use App\Rules\UniqueTaxonomyName;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Los datos de una etiqueta que llegan del formulario (`REQ-BOF-023` RB-3,
 * SP-2).
 *
 * Mismo criterio que `UpsertCategoryRequest`, con el tope de su columna: 80 y
 * no 120, porque una etiqueta es una marca corta y no un encabezado de
 * sección.
 *
 * Lo que **no** tiene es la comprobación del enlace bloqueado: el slug de una
 * etiqueta se edita siempre. La spec ya acepta que un filtro de etiqueta
 * compartido por URL deje de funcionar cuando la etiqueta se borra (SP-4), así
 * que protegerlo del renombrado sería cuidar una puerta con la de al lado
 * abierta.
 */
final class UpsertTagRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', new UniqueTaxonomyName(Tag::class, $this->editedId())],
            'slug' => ['nullable', 'string', 'max:80'],
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

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function slug(): ?string
    {
        $slug = trim((string) $this->string('slug'));

        return $slug === '' ? null : $slug;
    }

    private function editedId(): ?int
    {
        $tag = $this->route('tag');

        return $tag instanceof Tag ? (int) $tag->getKey() : null;
    }
}
