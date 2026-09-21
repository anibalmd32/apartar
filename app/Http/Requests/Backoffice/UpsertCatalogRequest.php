<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Catalog\CatalogData;
use App\Support\UniqueCatalogSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Los datos del formulario de catálogo (`REQ-BOF-024` RB-1).
 *
 * Los cuatro campos que no son de publicación. `is_listed` y `expires_at`
 * viajan en `PublishCatalogRequest` porque los escribe la publicación, y
 * `published_at` no viaja en ninguno: la fija la acción.
 *
 * ## El enlace duplicado **no** se valida aquí
 *
 * Es la diferencia con el nombre de una categoría, y es la regla de
 * `REQ-CAT-022` RB-1: el slug es único en toda la plataforma, así que
 * comprobarlo exigiría leer los catálogos de otro negocio —`REQ-ORG-002` RB-1
 * lo prohíbe fuera de `Platform`— y además la respuesta correcta a un choque
 * **no es un error**, es un sufijo. Quien decide es el índice, a través de
 * `UniqueCatalogSlug`.
 *
 * El enlace **bloqueado** tampoco se rechaza aquí: depende de un hecho del
 * catálogo —que ya se publicó— y no de lo que se escribió en el campo, así que
 * lo decide la acción. Es el mismo reparto que en la identidad del negocio.
 *
 * El permiso tampoco se comprueba en este archivo: lo hace la policy dentro de
 * la acción (`REQ-ORG-003` RB-4).
 */
final class UpsertCatalogRequest extends FormRequest
{
    /**
     * Tamaño máximo de la portada, en kilobytes.
     *
     * Cuatro megas: es una imagen ancha de campaña, no un logo, y el tope
     * existe para que una foto sin recortar de un móvil moderno no se cuele
     * entera en el almacenamiento —que es una métrica de plan
     * (`REQ-SUB-003`)—.
     */
    public const MAX_COVER_KILOBYTES = 4096;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // 160 es el tope de la columna ([05 §4.1]).
            'name' => ['required', 'string', 'max:160'],

            'slug' => ['nullable', 'string', 'max:'.UniqueCatalogSlug::MAX_LENGTH],

            'description' => ['nullable', 'string', 'max:2000'],

            'cover' => ['sometimes', 'nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:'.self::MAX_COVER_KILOBYTES],
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
            'description' => 'descripción',
            'cover' => 'portada',
        ];
    }

    /**
     * El formulario, ya normalizado.
     *
     * `slug` vacío y `slug` ausente significan lo mismo en los dos caminos:
     * al crear, «derívalo del nombre» (RB-1); al editar, «no lo toques», que
     * es lo que permite a un catálogo publicado seguir cambiando su nombre sin
     * chocar contra el cerrojo de RB-2.
     */
    public function toData(): CatalogData
    {
        $slug = trim((string) $this->string('slug'));
        $description = trim((string) $this->string('description'));
        $cover = $this->file('cover');

        return new CatalogData(
            name: trim((string) $this->string('name')),
            slug: $slug === '' ? null : $slug,
            description: $description === '' ? null : $description,
            cover: $cover instanceof UploadedFile ? $cover : null,
        );
    }
}
