<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\UsageCounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Consumo materializado de una métrica por organización (`REQ-SUB-003`).
 *
 * Tabla del tenant, una fila por métrica. Aquí solo se crea y se inicializa en
 * cero al dar de alta la suscripción (`REQ-SUB-002` RB-5); quien la mueve
 * —`UsageCalculator`, dentro de la transacción de cada mutación— y quien la
 * hace cumplir —`LimitEnforcer`— llegan con H5.
 *
 * @property int $id
 * @property int $organization_id
 * @property UsageMetric $metric
 * @property int $value
 * @property \Illuminate\Support\Carbon $computed_at
 */
class UsageCounter extends Model
{
    /** @use HasFactory<UsageCounterFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * `organization_id` queda fuera: lo rellena el trait
     * (`REQ-ORG-002` RB-2).
     *
     * @var list<string>
     */
    protected $fillable = [
        'metric',
        'value',
        'computed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric' => UsageMetric::class,
            'value' => 'integer',
            'computed_at' => 'datetime',
        ];
    }
}
