<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Una etiqueta del inventario de un negocio (`REQ-CAT-006`, `REQ-BOF-023`).
 *
 * La mitad blanda de la taxonomía: un producto lleva **una** categoría
 * obligatoria y **N** etiquetas opcionales (RB-2), y el filtro del catálogo
 * las combina con semántica **OR** (RB-4).
 *
 * A diferencia de la categoría, una etiqueta se puede borrar en cualquier
 * momento y simplemente se desasocia (`REQ-BOF-023` RB-5): por eso su pivote
 * borra en cascada y la FK de la categoría es `restrict`.
 *
 * Sin `position`: las etiquetas no se ordenan a mano.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name único por organización, y en la base (`REQ-BOF-023` RB-3)
 * @property string $slug viaja en la query string de los filtros del catálogo
 */
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * `organization_id` queda fuera: lo rellena el trait (`REQ-ORG-002` RB-2).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
    ];

    /**
     * Los productos que llevan la etiqueta.
     *
     * `product_tag` no tiene `id` ni `timestamps`: la fila **es** la pareja y
     * no hay nada más que preguntarle, así que la relación no declara pivote
     * alguno. Tampoco lleva `organization_id`, y no le hace falta: a una fila
     * solo se llega desde un producto o desde una etiqueta, los dos con el
     * global scope puesto ([05 §4.1]).
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    /**
     * Las etiquetas tal como se listan, con su conteo (RB-7).
     *
     * **Por nombre y no por `position`**, que es la otra cara de que la tabla
     * no tenga esa columna: el orden de una categoría es una decisión del
     * negocio y el de una etiqueta es alfabético, porque lo que se hace con
     * ellas es buscarlas en una lista.
     *
     * El conteo incluye `draft` y archivados, por lo mismo que el de
     * `Category::scopeOrdered()`.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->withCount('products')
            ->orderBy('name');
    }
}
