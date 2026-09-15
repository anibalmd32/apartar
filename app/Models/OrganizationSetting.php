<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\OrganizationSettingFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un ajuste de una organización (`REQ-ORG-006`, [02 §9.1]).
 *
 * Tabla del tenant con `UNIQUE(organization_id, key)`: cada negocio tiene su
 * propia fila de `grace_period_days` sin saber de la del vecino.
 *
 * El modelo es anémico a propósito (`REQ-QAX-001` RB-3) y **no es la forma en
 * que el resto del sistema lee los ajustes**: eso es `App\Support\Settings`,
 * con su caché por organización (`T-014`). Aquí existe porque el registro
 * siembra los diez valores por defecto dentro de su transacción, y hacerlo con
 * `DB::table()` se saltaría el trait que rellena `organization_id`
 * (`REQ-ORG-002` RB-2), que es justo la guarda que no conviene esquivar en la
 * operación que crea el tenant.
 *
 * @property int $id
 * @property int $organization_id
 * @property SettingKey $key
 * @property mixed $value
 * @property int|null $updated_by último usuario que lo cambió; nulo si es el valor sembrado
 */
class OrganizationSetting extends Model
{
    /** @use HasFactory<OrganizationSettingFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * `organization_id` queda fuera: lo rellena el trait.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key' => SettingKey::class,
        ];
    }

    /**
     * El valor del ajuste, del tipo con el que se guardó.
     *
     * Va como `Attribute` y no como cast `json` por un caso concreto:
     * `exchange_rate_manual` vale `null` mientras la tasa no sea manual, y el
     * cast de Laravel no serializa los nulos —los guarda como `NULL` de SQL, y
     * la columna es `JSON NOT NULL`—. Aquí se escribe el literal JSON `null`,
     * que es lo que de verdad significa: **el ajuste existe y su valor es
     * nulo**, no que le falte la fila.
     *
     * La distinción no es teórica: la pantalla de ajustes enseña las once
     * claves, y una que faltara obligaría a que la lectura conociera el
     * default, que es la segunda fuente de verdad que `SettingKey` evita.
     *
     * @return Attribute<mixed, string>
     */
    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): mixed => $value === null ? null : json_decode($value, true),
            set: fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Quién lo cambió por última vez.
     *
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
