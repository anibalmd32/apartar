<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\ProductSpecificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea de la ficha técnica de un producto (`REQ-CAT-004`).
 *
 * Filas y no una columna JSON (`ASU-CAT-03`): la ficha se ordena, se agrupa y
 * se lee campo a campo, y una lista de pares en JSON obligaría a reescribir el
 * documento entero para mover una línea.
 *
 * Lleva `organization_id` propio aunque cuelgue de un producto que ya lo
 * tiene (RB-6): es una tabla del tenant con su modelo, y el trait necesita la
 * columna para filtrar sin pasar por el producto. Es la diferencia con
 * `product_tag` y `catalog_product`, que no tienen modelo y por eso están
 * declarados exentos ([05 §4.1]).
 *
 * `label` y `value` se renderizan como **texto plano escapado** (RB-4): aquí
 * se guardan tal cual y no se sanean, porque escapar al guardar destruiría el
 * valor original y dejaría el escapado fuera del sitio donde se puede
 * verificar, que es la vista.
 *
 * ## `group` se llama `group`
 *
 * Es palabra reservada en MySQL y conserva el nombre a propósito ([05 §4]):
 * Laravel entrecomilla los identificadores, y renombrarla a `group_name`
 * rompería el contrato `SpecificationGroup` del módulo [11]. Nulo significa
 * «sin sección», y esas líneas se pintan primero bajo el rótulo «General»
 * (RB-2).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $product_id
 * @property string|null $group sección de la ficha; nulo = «General»
 * @property string $label
 * @property string $value
 * @property int $position orden dentro del grupo
 */
class ProductSpecification extends Model
{
    /** @use HasFactory<ProductSpecificationFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * `organization_id` queda fuera: lo rellena el trait (`REQ-ORG-002` RB-2).
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'group',
        'label',
        'value',
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
     * El producto cuya ficha describe.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
