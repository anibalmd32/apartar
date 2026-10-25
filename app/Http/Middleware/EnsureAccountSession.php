<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAccountSession
{
    public const CONNECT_GOOGLE_MESSAGE = 'Para seguir catálogos y ver sus novedades, conecta tu cuenta de Google.';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('account')->check()) {
            return $next($request);
        }

        if (Auth::guard('customer')->check()) {
            return redirect()->route('portal.dashboard')->with('status', self::CONNECT_GOOGLE_MESSAGE);
        }

        return redirect()->guest(route('account.google.redirect'));
    }
}
