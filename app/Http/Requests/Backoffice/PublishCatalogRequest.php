<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Catalog\PublishCatalogData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class PublishCatalogRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'is_listed' => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expires_at.date' => 'La fecha de caducidad no se entiende.',
        ];
    }

    public function toData(): PublishCatalogData
    {
        $expiresAt = $this->date('expires_at');

        if ($expiresAt === null) {
            return new PublishCatalogData(isListed: $this->boolean('is_listed'), expiresAt: null);
        }

        $expiresAt = CarbonImmutable::instance($expiresAt);

        return new PublishCatalogData(
            isListed: $this->boolean('is_listed'),
            expiresAt: $this->expiresOnAWholeDay() ? $expiresAt->endOfDay() : $expiresAt,
        );
    }

    private function expiresOnAWholeDay(): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $this->string('expires_at'))) === 1;
    }
}
