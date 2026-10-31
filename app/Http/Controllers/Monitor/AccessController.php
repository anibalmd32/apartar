<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitor;

use App\Http\Controllers\Controller;
use App\Support\MonitorAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class AccessController extends Controller
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 900;

    public function create(Request $request): View|RedirectResponse
    {
        abort_unless(MonitorAccess::configured(), 404);

        if (MonitorAccess::granted($request)) {
            return redirect()->to('/'.config('pulse.path', 'pulse'));
        }

        return view('monitor.access');
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(MonitorAccess::configured(), 404);

        $request->validate(['token' => ['required', 'string', 'max:512']]);

        $key = 'monitor-access:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['token' => sprintf('Demasiados intentos. Prueba en %d minutos.', (int) ceil(RateLimiter::availableIn($key) / 60))]);
        }

        if (! MonitorAccess::matches((string) $request->string('token'))) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            Log::warning('monitor.access.failed', ['ip' => $request->ip()]);

            throw ValidationException::withMessages(['token' => 'El token no es válido.']);
        }

        RateLimiter::clear($key);
        MonitorAccess::grant($request);

        return redirect()->intended('/'.config('pulse.path', 'pulse'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        MonitorAccess::revoke($request);

        return redirect()->route('monitor.access');
    }
}
