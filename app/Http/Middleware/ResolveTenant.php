<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Domain\Tenancy\TenantSurface;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class ResolveTenant implements DeclaresTenantSurface
{
    public function __construct(
        private TenantContext $context,
        private TenantResolver $resolver,
    ) {}

    public static function surface(): TenantSurface
    {
        return TenantSurface::Backoffice;
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $organization = $this->resolver->resolve(self::surface(), $request->user());
        } catch (ModelNotFoundException) {
            return $this->abandonSession($request);
        }

        if ($organization === null) {
            return $this->abandonSession($request);
        }

        $this->context->setOrganization($organization);

        return $next($request);
    }

    private function abandonSession(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->guest(route('login'));
    }
}
