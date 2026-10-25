<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @phpstan-require-extends Model
 */
trait RecordsLastEditor
{
    private ?int $pendingEditorId = null;

    public static function bootRecordsLastEditor(): void
    {
        static::saving(static function (Model $model): void {
            /** @var static $model */
            if ($model->pendingEditorId !== null && $model->isDirty()) {
                $model->setAttribute('updated_by', $model->pendingEditorId);
            }
        });

        static::saved(static function (Model $model): void {
            /** @var static $model */
            $model->pendingEditorId = null;
        });
    }

    public function editedBy(User $actor): static
    {
        $this->pendingEditorId = (int) $actor->getKey();

        return $this;
    }

    public function touchedBy(User $actor): bool
    {
        $this->setAttribute('updated_by', (int) $actor->getKey());

        return $this->touch();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
