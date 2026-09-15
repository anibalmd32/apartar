<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\OrganizationStatus;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un negocio dentro de la plataforma: **el tenant**.
 *
 * No lleva `organization_id` ni el trait `BelongsToOrganization` porque no
 * está sujeta a ningún tenant: es el tenant. De ahí que su `slug` sea único en
 * toda la plataforma y no por organización.
 *
 * El modelo es anémico a propósito (`REQ-QAX-001` RB-3): relaciones, casts y
 * accesores triviales. Las reglas de suspensión, cierre y capacidades viven en
 * `app/Domain/Tenancy` y en las `Action` correspondientes.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $document RIF, opcional hasta la primera factura
 * @property string|null $phone teléfono de contacto; se captura en ajustes, no en el registro
 * @property string $timezone zona con la que se presentan sus fechas
 * @property string|null $logo_path
 * @property string|null $brand_color
 * @property bool $storefront_enabled
 * @property OrganizationStatus $status
 * @property \Illuminate\Support\Carbon|null $suspended_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'document',
        'phone',
        'timezone',
        'logo_path',
        'brand_color',
        'storefront_enabled',
        'status',
        'suspended_at',
    ];

    /**
     * Clave de ruta pública: el `slug`, nunca el `id`.
     *
     * Lo expuesto públicamente es siempre `slug` o `code` ([02 §5]); el `id`
     * es un detalle interno.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'storefront_enabled' => 'boolean',
            'status' => OrganizationStatus::class,
            'suspended_at' => 'datetime',
        ];
    }
}
