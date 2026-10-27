<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\MobilePrefix;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $mobile_prefix_id
 * @property-read MobilePrefix|null $mobilePrefix
 */
trait ReferencesMobilePrefix
{
    public static function bootReferencesMobilePrefix(): void
    {
        static::saving(static function (self $model): void {
            $column = $model->phoneColumn();

            if ($model->exists && ! $model->isDirty($column) && ! $model->isDirty('method')) {
                return;
            }

            $phone = $model->carriesMobilePhone() ? $model->getAttribute($column) : null;
            $model->setAttribute('mobile_prefix_id', MobilePrefix::ofPhone(is_string($phone) ? $phone : null)?->getKey());
        });
    }

    /**
     * @return BelongsTo<MobilePrefix, $this>
     */
    public function mobilePrefix(): BelongsTo
    {
        return $this->belongsTo(MobilePrefix::class);
    }

    public function phoneColumn(): string
    {
        return 'phone';
    }

    public function carriesMobilePhone(): bool
    {
        return true;
    }
}
