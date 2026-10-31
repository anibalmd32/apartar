<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class RedirectAcrossSurfaces
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->header('X-Inertia') || ! $response instanceof RedirectResponse) {
            return $response;
        }

        $target = $response->getTargetUrl();
        $host = parse_url($target, PHP_URL_HOST);

        if (! is_string($host) || strcasecmp($host, $request->getHost()) === 0) {
            return $response;
        }

        return Inertia::location($target);
    }
}
