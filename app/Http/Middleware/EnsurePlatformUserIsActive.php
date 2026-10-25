<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PlatformUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePlatformUserIsActive
{
    private const PASSWORD_ROUTES = ['platform.password.edit', 'platform.password.update', 'platform.logout'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('platform');
        $user = $guard->user();

        if (! $user instanceof PlatformUser) {
            return $request->expectsJson() ? abort(401) : redirect()->guest(route('platform.login'));
        }

        if (! $user->isActive()) {
            $guard->logout();
            $request->session()->regenerate();

            return redirect()->route('platform.login')->with('status', 'Este acceso está desactivado.');
        }

        Auth::shouldUse('platform');

        if ($user->must_change_password && ! in_array($request->route()?->getName(), self::PASSWORD_ROUTES, true)) {
            return redirect()->route('platform.password.edit');
        }

        return $next($request);
    }
}
