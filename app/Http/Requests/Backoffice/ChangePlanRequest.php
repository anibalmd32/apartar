<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Domain\Subscription\LimitEnforcer;
use App\Models\Plan;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangePlanRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $current = app(LimitEnforcer::class)->plan()->code;

        return [
            'plan' => ['required', 'string', 'max:50', Rule::exists('plans', 'code')->where(
                fn (Builder $query) => $query->where('is_public', true)->orWhere('code', $current),
            )],
            'confirmed' => ['sometimes', 'boolean'],
            'document' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan.required' => 'Elige un plan.',
            'plan.exists' => 'Ese plan no está a la venta.',
        ];
    }

    public function plan(): Plan
    {
        return Plan::query()->where('code', (string) $this->string('plan'))->firstOrFail();
    }

    public function confirmed(): bool
    {
        return $this->boolean('confirmed');
    }

    public function document(): ?string
    {
        $document = trim((string) $this->string('document'));

        return $document === '' ? null : $document;
    }
}
