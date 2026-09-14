<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice\Auth;

use App\Domain\Tenancy\AdminDisabledException;
use App\Domain\Tenancy\OrganizationNotActiveException;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Intento de acceso al backoffice (`REQ-BOF-001`).
 *
 * Heredó del andamiaje del starter kit lo que ya estaba bien —la clave de rate
 * limit por `email|ip`, que es exactamente la de RB-3— y cambia tres cosas:
 *
 *  - **el `429` con `Retry-After`** en lugar de un error de validación: el
 *    bloqueo no es un campo mal escrito, y un cliente que reintenta necesita
 *    saber cuándo puede;
 *  - **el mensaje genérico** de SP-1, que no distingue «ese correo no existe»
 *    de «esa contraseña está mal». Con `users.email` único en toda la
 *    plataforma, distinguirlos convertiría el login en un oráculo que dice si
 *    una persona tiene cuenta —y con ella, en qué negocio trabaja—;
 *  - **el registro del intento fallido** con correo e IP y **nunca la
 *    contraseña** (RB-8).
 *
 * **No pide la organización** (RB-2), y no es una comodidad: `users.email` es
 * único en toda la plataforma precisamente para que no haga falta. La
 * organización se deriva del usuario y la fija `ResolveTenant`.
 */
class LoginRequest extends FormRequest
{
    /**
     * Cinco intentos por (correo + IP) cada quince minutos (RB-3).
     */
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 900;

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
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Autentica, o explica lo justo.
     *
     * El orden importa: primero el rate limit —si no, cada intento bloqueado
     * seguiría costando una consulta y un `bcrypt`—, después las credenciales,
     * y solo entonces las comprobaciones de estado. Mirar si el usuario está
     * desactivado **antes** de validar la contraseña convertiría el login en
     * el oráculo que SP-1 evita.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::guard('web')->attempt($this->credentials(), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            $this->recordFailure('credenciales inválidas');

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $this->ensureTheTeamMemberMayEnter();

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * RB-4 y RB-10 · quien ya no forma parte del equipo, y el negocio cerrado.
     *
     * Ocurre **después** de validar la contraseña: hasta aquí solo llega quien
     * ha demostrado ser quien dice, así que el motivo concreto ya no revela
     * nada a un desconocido.
     *
     * @throws ValidationException
     */
    private function ensureTheTeamMemberMayEnter(): void
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User) {
            return;
        }

        $rejection = match (true) {
            $user->disabled_at !== null => AdminDisabledException::MESSAGE,
            $user->organization->status === OrganizationStatus::Closed => OrganizationNotActiveException::CLOSED_MESSAGE,
            default => null,
        };

        if ($rejection === null) {
            return;
        }

        Auth::guard('web')->logout();

        $this->recordFailure($rejection);

        throw ValidationException::withMessages(['email' => $rejection]);
    }

    /**
     * RB-3 · al sexto intento, `429` con `Retry-After`.
     */
    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        $this->recordFailure('demasiados intentos');

        abort(
            Response::HTTP_TOO_MANY_REQUESTS,
            __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
            ['Retry-After' => (string) $seconds],
        );
    }

    /**
     * RB-8 · el intento fallido se registra en el log, **nunca la
     * contraseña**.
     *
     * Va al log y no a `activity_log` a propósito: la auditoría de negocio es
     * de lo que hace un usuario dentro de su organización, y aquí todavía no
     * hay usuario ni organización de la que colgar la entrada.
     */
    private function recordFailure(string $reason): void
    {
        Log::warning('backoffice.login.failed', [
            'email' => $this->string('email')->lower()->value(),
            'ip' => $this->ip(),
            'reason' => $reason,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function credentials(): array
    {
        return [
            'email' => $this->string('email')->lower()->value(),
            'password' => (string) $this->string('password'),
        ];
    }

    /**
     * Clave del rate limit: correo **y** IP.
     *
     * Solo por IP dejaría fuera a una oficina entera por culpa de uno; solo
     * por correo permitiría probar una contraseña contra mil correos desde la
     * misma máquina.
     */
    public function throttleKey(): string
    {
        return Str::transliterate($this->string('email')->lower().'|'.$this->ip());
    }
}
