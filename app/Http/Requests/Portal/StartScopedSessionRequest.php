<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Actions\Customer\StartScopedSessionAction;
use App\Domain\Customer\AccessVerificationFailedException;
use App\Models\Customer;
use App\Support\DocumentNumber;
use App\Support\InvalidDocumentNumberException;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class StartScopedSessionRequest extends FormRequest
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 3600;

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
            'document' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.required' => 'Escribe tu cédula o RIF.',
            'code.required' => 'Escribe el código de tu apartado.',
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(StartScopedSessionAction $start): Customer
    {
        $document = $this->document();

        $this->ensureIsNotRateLimited();

        try {
            $customer = $start->handle($document, (string) $this->string('code'));
        } catch (AccessVerificationFailedException $failure) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            Log::warning('portal.access.failed', ['ip' => $this->ip(), 'reason' => $failure->reason]);

            throw ValidationException::withMessages(['code' => $failure->getMessage()]);
        }

        RateLimiter::clear($this->throttleKey());

        return $customer;
    }

    public function throttleKey(): string
    {
        $document = DocumentNumber::tryFromInput($this->input('document'));

        return 'portal-access|'.Str::transliterate(($document->value ?? Str::lower((string) $this->string('document'))).'|'.$this->ip());
    }

    /**
     * @throws ValidationException
     */
    private function document(): DocumentNumber
    {
        try {
            return DocumentNumber::fromInput((string) $this->string('document'));
        } catch (InvalidDocumentNumberException $invalid) {
            throw ValidationException::withMessages(['document' => $invalid->getMessage()]);
        }
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        Log::warning('portal.access.failed', ['ip' => $this->ip(), 'reason' => 'demasiados intentos']);

        abort(
            Response::HTTP_TOO_MANY_REQUESTS,
            __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
            ['Retry-After' => (string) $seconds],
        );
    }
}
