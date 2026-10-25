<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\OrganizationRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureIsOwner
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->role !== OrganizationRole::Owner) {
            abort(Response::HTTP_FORBIDDEN, 'Esta sección es del dueño de la organización.');
        }

        return $next($request);
    }
}
