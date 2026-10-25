<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Account\PortalContext;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePortalSession
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->forgetInstance(PortalContext::class);

        if (Auth::guard('account')->check()) {
            return $next($request);
        }

        $guard = Auth::guard('customer');

        if (! $guard->check()) {
            return redirect()->guest(route('portal.access'));
        }

        if ($guard instanceof SessionGuard && (string) $request->session()->get($guard->getName()) !== (string) $guard->id()) {
            $request->session()->put($guard->getName(), $guard->id());
            $request->session()->migrate(true);
        }

        return $next($request);
    }
}
