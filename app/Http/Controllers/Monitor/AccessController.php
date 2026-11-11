<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitor;

use App\Http\Controllers\Controller;
use App\Support\MonitorAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

final class AccessController extends Controller
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 900;

    private const MAX_LENGTH = 512;

    public function create(Request $request): Response|RedirectResponse
    {
        if (! MonitorAccess::configured()) {
            return MonitorAccess::nothing();
        }

        if (MonitorAccess::granted($request)) {
            return $this->panels();
        }

        return response()->view('monitor.access', ['retry' => $request->session()->get(MonitorAccess::RETRY_KEY) === true]);
    }

    public function store(Request $request): Response|RedirectResponse
    {
        if (! MonitorAccess::configured()) {
            return MonitorAccess::nothing();
        }

        $key = 'monitor-access:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return $this->retry();
        }

        $candidate = $request->input(MonitorAccess::FIELD);

        if (! is_string($candidate) || strlen($candidate) > self::MAX_LENGTH || ! MonitorAccess::matches($candidate)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            Log::warning('monitor.access.failed', ['ip' => $request->ip()]);

            return $this->retry();
        }

        RateLimiter::clear($key);
        MonitorAccess::grant($request);

        return $this->panels();
    }

    public function destroy(Request $request): RedirectResponse
    {
        MonitorAccess::revoke($request);

        return redirect()->route('monitor.access');
    }

    private function retry(): RedirectResponse
    {
        return redirect()->route('monitor.access')->with(MonitorAccess::RETRY_KEY, true);
    }

    private function panels(): RedirectResponse
    {
        return redirect()->to('/'.config('pulse.path', 'pulse'));
    }
}
