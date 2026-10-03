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

/**
 * El formulario «tengo el código de mi apartado» (`REQ-IDN-008`).
 *
 * Valida la forma, pone el límite de intentos y llama a
 * `StartScopedSessionAction`. Es la misma forma que el acceso del backoffice
 * (`LoginRequest`, `T-012`), con su límite y su error genérico.
 *
 * ## El límite, antes que la consulta
 *
 * RB-5: **cinco intentos por hora por IP y documento**; el sexto es un `429`
 * con `Retry-After` (SP-4). Solo por IP dejaría fuera a un cibercafé entero
 * por culpa de uno; solo por documento permitiría probar mil códigos contra la
 * misma cédula desde máquinas distintas, que es justo el ataque que importa
 * —la cédula es semipública y el código es la mitad secreta—.
 *
 * ## Qué cuenta como intento
 *
 * Los tres fallos de verificación, que son el mismo mensaje en el campo del
 * código (SP-1 a SP-3). Una cédula mal escrita **no** consume intento: se
 * rechaza por su forma, con el mensaje de `DocumentNumber`, y eso no dice
 * nada de si existe en algún negocio.
 */
class StartScopedSessionRequest extends FormRequest
{
    /** RB-5 · cinco intentos… */
    public const MAX_ATTEMPTS = 5;

    /** …por hora. */
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
     * Abre la sesión, o explica lo justo.
     *
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

    /**
     * Clave del límite: el documento **canónico** y la IP, para que
     * `V-12.345.678` y `v12345678` sean el mismo contador.
     */
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

    /**
     * SP-4 · al sexto intento, `429` con `Retry-After`.
     */
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
