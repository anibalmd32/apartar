<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\CatalogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Una selección de productos con su propia URL pública (`REQ-CAT-021`,
 * `REQ-CAT-022`).
 *
 * Con el pivote, el catálogo **es la tienda entera** desde el punto de vista
 * del cliente final: la única puerta de entrada, y la pieza de la que se
 * derivan la organización, la taxonomía, la tasa, las cuentas de cobro y el
 * branding de toda la cara pública (`REQ-CAT-021` RB-3).
 *
 * Tabla del tenant: lleva `organization_id` y su global scope. Lo que **no**
 * lleva es el `slug` acotado por organización — es único en toda la
 * plataforma, porque es una URL pública ([05 §4.1]).
 *
 * El modelo es anémico salvo por `visibility()` (`REQ-QAX-001` RB-3): quién
 * puede publicar y con qué seis requisitos es de `PublishCatalogAction`
 * (`T-032`), y qué productos entran y en qué orden es de
 * `SyncCatalogProductsAction` (`T-031`).
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

    /**
     * Estado de visibilidad del catálogo, **derivado** de sus tres columnas
     * (`REQ-CAT-022` RB-6).
     *
     * No hay columna `visibility` y no debe haberla: sería la misma lista
     * escrita dos veces, y el día que discrepara, un catálogo con la columna
     * en `listed` y las fechas en `expired` seguiría vendiendo con precios de
     * una campaña terminada.
     *
     * **Los cuatro casos se solapan, así que el orden es la regla:**
     *
     *  1. Sin `published_at` es borrador, **aunque `expires_at` ya haya
     *     pasado**: lo que nunca se publicó no puede haber expirado, y
     *     despublicar devuelve a borrador precisamente vaciando esta columna
     *     (RB-7). Es el cruce que se tuerce solo si se evalúa la expiración
     *     primero.
     *  2. Con `expires_at` pasada es expirado, **por delante de
     *     `is_listed`**: un catálogo caducado no aparece en la vitrina ni se
     *     sirve por enlace, y su URL responde `410` (`REQ-CAT-021` SP-3).
     *  3. Solo entonces decide `is_listed`, que no es un control de acceso
     *     (RB-3): separa «en mi vitrina» de «accesible por enlace».
     *
     * Un `published_at` **futuro** cuenta como publicado. Para el producto la
     * fecha futura es publicación programada (`REQ-CAT-007` RB-5), pero para
     * el catálogo no existe tal cosa: `REQ-CAT-022` deja la programación de la
     * publicación fuera de alcance —«solo se programa la expiración»— y
     * `PublishCatalogAction` fija la fecha al publicar. Inventar aquí un
     * quinto estado «programado» sería adelantar una función que la spec no
     * pide.
     *
     * El reloj se consulta en cada llamada y no hay job de por medio (RB-6):
     * un catálogo expira a su hora aunque la cola esté parada.
     */
    public function visibility(): CatalogVisibility
    {
        if ($this->published_at === null) {
            return CatalogVisibility::Draft;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return CatalogVisibility::Expired;
        }

        return $this->is_listed
            ? CatalogVisibility::Listed
            : CatalogVisibility::Unlisted;
    }

    /**
     * Los productos del catálogo, en el orden curado (`REQ-CAT-023`).
     *
     * **No filtra por estado**, y es deliberado: los archivados conservan su
     * relación con el catálogo (`REQ-CAT-007` RB-7) para que desarchivarlos
     * los devuelva sin recomponer nada. Quien omite los `draft` y los
     * `archived` es la consulta pública (`REQ-CAT-021` RB-4); un
     * `where('status', …)` metido aquí desactivaría RB-7 sin que nada fallara.
     *
     * `withPivot('position', 'created_at')` y **no `withTimestamps()`**:
     * `catalog_product` no tiene `updated_at`, así que pedirlo haría que cada
     * `attach()` escribiera una columna inexistente. El `created_at` lo pone
     * la base y registra cuándo entró el producto aquí — es lo que ordena el
     * feed y define qué es novedad (RB-3), y por eso no se toca al reordenar.
     *
     * Tampoco ordena. El orden curado es el **defecto de la vista pública**
     * (`REQ-CAT-006` RB-5), no una propiedad de la relación: cablearlo aquí
     * pondría `position` por delante en todo `sort` que el cliente eligiera, y
     * `price_asc` ordenaría por posición y desempataría por precio.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('position', 'created_at');
    }
}
