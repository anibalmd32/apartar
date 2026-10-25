<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CatalogSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $account_id
 * @property int $catalog_id
 * @property \Illuminate\Support\Carbon $subscribed_at
 * @property \Illuminate\Support\Carbon|null $muted_at
 */
class CatalogSubscription extends Model
{
    /** @use HasFactory<CatalogSubscriptionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'account_id',
        'catalog_id',
        'subscribed_at',
        'muted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subscribed_at' => 'datetime',
            'muted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Catalog, $this>
     */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }
}
