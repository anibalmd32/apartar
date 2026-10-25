<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use DateTimeInterface;
use Illuminate\Foundation\Http\FormRequest;

final class PublishProductRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'published_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'published_at.date' => 'La fecha de publicación no se entiende.',
        ];
    }

    public function publishedAt(): ?DateTimeInterface
    {
        return $this->date('published_at');
    }
}
