<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $catalog_id
 * @property int $account_id
 * @property \Illuminate\Support\Carbon $digest_date
 * @property int $products_count
 * @property \Illuminate\Support\Carbon|null $sent_at
 */
class CatalogDigestDispatch extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['digest_date' => 'date', 'sent_at' => 'datetime'];
    }
}
