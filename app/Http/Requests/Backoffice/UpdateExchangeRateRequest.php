<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Domain\Exchange\RateSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateExchangeRateRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'exchange_rate_source' => [
                'required',
                'string',
                Rule::in(array_map(fn (RateSource $source): string => $source->value, RateSource::cases())),
            ],

            'exchange_rate_manual' => ['sometimes', 'nullable'],
        ];
    }

    public function source(): RateSource
    {
        return RateSource::from((string) $this->validated('exchange_rate_source'));
    }

    public function manualRate(): ?string
    {
        if (! $this->has('exchange_rate_manual')) {
            return null;
        }

        $rate = $this->validated('exchange_rate_manual');

        if ($rate === null || $rate === '') {
            return null;
        }

        return is_string($rate) ? trim($rate) : (string) $rate;
    }
}
