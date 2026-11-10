<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Safeguard\SafeguardDestination;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $payment_id
 * @property SafeguardDestination $destination
 * @property string|null $reference
 * @property string|null $proof_path
 * @property string|null $proof_mime
 * @property string|null $note
 * @property int|null $recorded_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PaymentSafeguard extends Model
{
    use BelongsToOrganization;

    /**
     * @var list<string>
     */
    protected $fillable = ['destination', 'reference', 'note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'destination' => SafeguardDestination::class,
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withoutGlobalScope(Payment::NOT_VOIDED);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
