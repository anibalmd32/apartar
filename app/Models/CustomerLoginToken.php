<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un enlace de acceso al portal por correo (`REQ-IDN-003`).
 *
 * Es una credencial de 30 minutos y un solo uso (RB-4): solo guarda el hash,
 * y se da por gastada al canjearla (`consumed_at`) o al emitir otra para la
 * misma ficha (RB-5), que la caduca moviendo `expires_at` al instante.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $customer_id
 * @property string $token_hash
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $consumed_at
 * @property string $requested_ip
 */
class CustomerLoginToken extends Model
{
    use BelongsToOrganization;

    /**
     * Si todavía abre algo: sin canjear y sin caducar.
     */
    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
