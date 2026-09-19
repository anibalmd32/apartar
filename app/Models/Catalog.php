<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\CatalogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Una selección de productos con su propia URL pública (`REQ-CAT-021`,
 * `REQ-CAT-022`).
 *
 * **Está en su forma mínima a propósito.** `T-024` lo adelanta porque
 * `PublishedCatalogs::exist()` necesita consultar la tabla en cuanto existe
 * —si no, `REQ-ORG-006` SP-5 quedaría desactivado durante ocho tareas sin un
 * solo test en rojo—, y para eso basta con el trait del tenant y el `$fillable`
 * que usan las factories.
 *
 * Lo que falta es de `T-025`: el enum `CatalogVisibility` y los estados
 * derivados de `published_at`/`expires_at`, los casts, y las relaciones con
 * `products` y con las suscripciones. Escribirlos aquí ahora sería adelantar
 * la tarea siguiente sobre columnas que ningún código lee todavía.
 *
 * Tabla del tenant: lleva `organization_id` y su global scope. Lo que **no**
 * lleva es el `slug` acotado por organización — es único en toda la
 * plataforma, porque es una URL pública ([05 §4.1]).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $slug único en toda la plataforma
 * @property string|null $description
 * @property string|null $cover_path
 * @property bool $is_listed aparece en la vitrina del negocio; no controla el acceso
 * @property \Illuminate\Support\Carbon|null $published_at nulo = borrador
 * @property \Illuminate\Support\Carbon|null $expires_at
 */
class Catalog extends Model
{
    /** @use HasFactory<CatalogFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * `organization_id` queda fuera: lo rellena el trait (`REQ-ORG-002` RB-2).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'cover_path',
        'is_listed',
        'published_at',
        'expires_at',
    ];

    /**
     * Los casts mínimos para que `published_at` se pueda comparar y `is_listed`
     * no llegue como `0`/`1`. El resto —la visibilidad derivada— es de `T-025`.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_listed' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
