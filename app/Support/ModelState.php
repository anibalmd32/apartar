<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

final class ModelState
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function adopt(Model $model, array $attributes): void
    {
        $model->setRawAttributes($attributes);
        $model->syncOriginal();
    }
}
