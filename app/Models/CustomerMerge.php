<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $absorbed_customer_id
 * @property string $absorbed_document_number
 * @property string|null $absorbed_remember_token
 * @property int $survivor_customer_id
 * @property int|null $merged_by
 */
class CustomerMerge extends Model
{
    use BelongsToOrganization;

    /** @var list<string> */
    protected $hidden = ['absorbed_remember_token'];

    public static function survivorOf(int $absorbedCustomerId): ?Customer
    {
        $seen = [];
        $id = $absorbedCustomerId;

        while (($merge = self::query()->where('absorbed_customer_id', $id)->first()) instanceof self) {
            if (isset($seen[$merge->survivor_customer_id])) {
                return null;
            }

            $seen[$merge->survivor_customer_id] = true;
            $id = $merge->survivor_customer_id;
        }

        return $id === $absorbedCustomerId ? null : Customer::query()->find($id);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function survivor(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'survivor_customer_id');
    }
}
