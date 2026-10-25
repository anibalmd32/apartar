<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Models\PlatformUser;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class LoginRequest extends FormRequest
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 900;

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
        ];
    }

    public function authenticate(): PlatformUser
    {
        $this->ensureIsNotRateLimited();

        $guard = Auth::guard('platform');
        $credentials = ['email' => $this->string('email')->lower()->value(), 'password' => (string) $this->string('password')];

        if (! $guard->attempt($credentials)) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
            Log::warning('platform.login.failed', ['email' => $credentials['email'], 'ip' => $this->ip()]);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $user = $guard->user();

        if (! $user instanceof PlatformUser || ! $user->isActive()) {
            $guard->logout();
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            throw ValidationException::withMessages(['email' => 'Este acceso está desactivado.']);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

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

    public function throttleKey(): string
    {
        return 'plataforma|'.Str::transliterate($this->string('email')->lower().'|'.$this->ip());
    }
}
