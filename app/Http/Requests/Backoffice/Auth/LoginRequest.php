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

class LoginRequest extends FormRequest
{
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

    public function throttleKey(): string
    {
        return Str::transliterate($this->string('email')->lower().'|'.$this->ip());
    }
}
