<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\PaymentAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Cuenta de cobro de una organización: dónde le pagan (`REQ-ORG-007`).
 *
 * Tabla del tenant. El catálogo se la muestra al cliente al apartar, y sin al
 * menos una activa no se puede publicar un catálogo (`REQ-CAT-022`).
 *
 * El modelo es anémico a propósito (`REQ-QAX-001` RB-3): el enum
 * `PaymentMethod` con sus tres métodos y la regla `ValidPaymentIdentifier`
 * llegan con `T-015`.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $method método de pago (`PaymentMethod`)
 * @property string|null $bank
 * @property string $holder_name titular de la cuenta
 * @property string $holder_document cédula o RIF del titular
 * @property string $identifier teléfono, número de cuenta, correo o wallet, según el método
 * @property string|null $instructions nota corta que se muestra al cliente
 * @property bool $is_active
 * @property int $position
 */
class PaymentAccount extends Model
{
    /** @use HasFactory<PaymentAccountFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * `organization_id` queda fuera: lo rellena el trait (`REQ-ORG-002` RB-2).
     *
     * @var list<string>
     */
    protected $fillable = [
        'method',
        'bank',
        'holder_name',
        'holder_document',
        'identifier',
        'instructions',
        'is_active',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
