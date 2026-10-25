<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Models\Message;
use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.Message::MAX_LENGTH],
            'idempotencyKey' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Escribe un mensaje.',
            'body.max' => 'El mensaje no puede pasar de :max caracteres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        if (is_string($body)) {
            $this->merge(['body' => trim($body)]);
        }
    }

    public function body(): string
    {
        return (string) $this->string('body');
    }

    public function idempotencyKey(): string
    {
        return (string) $this->string('idempotencyKey');
    }
}
