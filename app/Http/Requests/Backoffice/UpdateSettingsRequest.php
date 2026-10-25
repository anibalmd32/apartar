<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateSettingsRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        /** @var array<string, mixed> $settings */
        $settings = $this->validated('settings');

        return $settings;
    }
}
