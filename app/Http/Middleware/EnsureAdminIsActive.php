<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\AdminDisabledException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdminIsActive
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->disabled_at !== null) {
            return $this->endSession($request);
        }

        return $next($request);
    }

    private function endSession(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()
            ->route('login')
            ->withErrors(['email' => AdminDisabledException::MESSAGE]);
    }
}
