<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Bank;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $bank_id
 * @property-read Bank|null $catalogBank
 */
trait ReferencesBank
{
    public static function bootReferencesBank(): void
    {
        static::saving(static function (self $model): void {
            if (! $model->isDirty('bank') && $model->exists) {
                return;
            }

            $bank = Bank::matching($model->getAttribute('bank'));
            $model->setAttribute('bank_id', $bank?->getKey());

            if ($bank !== null) {
                $model->setAttribute('bank', $bank->name);
            }
        });
    }

    /**
     * @return BelongsTo<Bank, $this>
     */
    public function catalogBank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }
}
