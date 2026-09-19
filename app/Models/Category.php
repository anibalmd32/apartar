<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La categoría de un producto dentro de un negocio (`REQ-CAT-006`,
 * `REQ-BOF-023`).
 *
 * Tabla del tenant, y **la taxonomía es de cada organización** (RB-1): no hay
 * categorías compartidas ni sugeridas por la plataforma. «Motores» de una
 * ferretería no es comparable con el de otra, y es la razón por la que el feed
 * multi-negocio no filtra por categoría (`REQ-ACC-003` RB-5).
 *
 * Planas y ordenadas a mano (RB-2): sin jerarquía, con `position` guardando el
 * orden que el negocio decide arrastrando.
 *
 * El modelo es anémico a propósito (`REQ-QAX-001` RB-3): quién puede borrarla
 * y qué pasa con sus productos es de `ReassignCategoryProductsAction`
 * (`T-026`).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name único por organización, y en la base (`REQ-BOF-023` RB-3)
 * @property string $slug viaja en la query string de los filtros del catálogo
 * @property int $position orden manual dentro de la organización
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * `organization_id` queda fuera: lo rellena el trait (`REQ-ORG-002` RB-2).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * Los productos de la categoría.
     *
     * Sin filtro por estado: la relación es la pertenencia, no la visibilidad.
     * Es lo que necesita `ReassignCategoryProductsAction` para mover **todos**
     * los productos antes de borrar la categoría (`REQ-BOF-023` RB-4), y lo
     * que hace que la FK `restrict` de `products.category_id` tenga algo que
     * ofrecer en lugar de un error sin salida.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * La taxonomía tal como se lista, **con su conteo** (RB-2, RB-7).
     *
     * Las dos mitades van juntas porque la pantalla nunca necesita una sin la
     * otra: la lista es arrastrable —de ahí `position`— y cada fila enseña
     * cuántos productos caen dentro, que es lo que convierte «no puedes borrar
     * esta categoría» en una tarea con tamaño conocido.
     *
     * **El conteo incluye los `draft`** —lo pide RB-7 y lo hace `withCount`
     * solo— **y también los archivados**, que la regla no nombra: un archivado
     * sigue ocupando su categoría y sigue impidiendo borrarla, así que
     * descontarlo enseñaría «0 productos» al lado de una categoría que luego
     * se niega a desaparecer. Lo que sí queda fuera es lo borrado lógicamente,
     * porque la relación lleva el `SoftDeletingScope` del producto.
     *
     * `position` manda y el identificador desempata: sin el segundo criterio,
     * dos categorías que compartieran posición saldrían en el orden que
     * quisiera MySQL y la lista bailaría sin que nadie la tocara.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->withCount('products')
            ->orderBy('position')
            ->orderBy('id');
    }
}
