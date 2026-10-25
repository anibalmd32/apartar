<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Translation\PotentiallyTranslatedString;

final readonly class UniqueTaxonomyName implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        private string $model,
        private ?int $ignoreId = null,
    ) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $query = $this->model::query()->where('name', trim($value));

        if ($this->ignoreId !== null) {
            $query->whereKeyNot($this->ignoreId);
        }

        if ($query->exists()) {
            $fail('Ya tienes algo con ese nombre. Usa el que ya existe o elige otro nombre.');
        }
    }
}
