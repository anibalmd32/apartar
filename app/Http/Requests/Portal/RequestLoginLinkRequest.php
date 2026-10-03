<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Actions\Customer\IssueLoginLinkAction;
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
 * El formulario «envíame un enlace» (`REQ-IDN-003`).
 *
 * ## El límite cuenta **solicitudes**, no fallos
 *
 * RB-6: tres por cada 15 minutos por IP y documento, y la cuarta es `429`
 * (SP-6). A diferencia del acceso por código, aquí cuenta **toda** solicitud,
 * coincida o no: cada una puede mandar correos, y un límite que solo contara
 * los fallos dejaría a cualquiera inundar el buzón de una persona cuyo
 * documento y correo conoce.
 *
 * Una cédula mal escrita se rechaza por su forma y no cuenta: no manda nada.
 */
class RequestLoginLinkRequest extends FormRequest
{
    /** RB-6 · tres solicitudes… */
    public const MAX_ATTEMPTS = 3;

    /** …cada quince minutos. */
    public const DECAY_SECONDS = 900;

    /** Lo que se le dice a la persona, coincida o no (RB-2, SP-3). */
    public const SENT_MESSAGE = 'Si esos datos coinciden con un apartado que dejó ese correo, te enviamos un enlace para entrar. Revisa tu bandeja; caduca en 30 minutos.';

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
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.required' => 'Escribe tu cédula o RIF.',
            'email.required' => 'Escribe el correo que dejaste al apartar.',
            'email.email' => 'Ese correo no tiene forma de correo.',
        ];
    }

    /**
     * Pide los enlaces, con el límite delante.
     *
     * @throws ValidationException
     */
    public function issue(IssueLoginLinkAction $issue): void
    {
        $document = $this->document();

        $this->ensureIsNotRateLimited();

        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

        $sent = $issue->handle($document, (string) $this->string('email'), (string) $this->ip());

        Log::info('portal.login_link.requested', ['ip' => $this->ip(), 'sent' => $sent]);
    }

    public function throttleKey(): string
    {
        $document = DocumentNumber::tryFromInput($this->input('document'));

        return 'portal-login-link|'.Str::transliterate(($document->value ?? Str::lower((string) $this->string('document'))).'|'.$this->ip());
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
     * SP-6 · la cuarta, `429` con `Retry-After`.
     */
    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        abort(
            Response::HTTP_TOO_MANY_REQUESTS,
            __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
            ['Retry-After' => (string) $seconds],
        );
    }
}
