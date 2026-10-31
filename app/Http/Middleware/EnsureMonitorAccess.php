<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\MonitorAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureMonitorAccess
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (MonitorAccess::granted($request)) {
            return $next($request);
        }

        if (! MonitorAccess::configured()) {
            abort(404);
        }

        if ($request->expectsJson() || ! $request->isMethod('GET')) {
            abort(403);
        }

        return redirect()->guest(route('monitor.access'));
    }
}
