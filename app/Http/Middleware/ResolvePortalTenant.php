<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Account\PortalContext;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Domain\Tenancy\TenantSurface;
use App\Models\Customer;
use App\Models\Organization;
use App\Support\LayawayCode;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class ResolvePortalTenant implements DeclaresTenantSurface
{
    public const BUSINESS_PARAMETER = 'negocio';

    public function __construct(
        private TenantContext $context,
        private TenantResolver $resolver,
        private PortalContext $portal,
    ) {}

    public static function surface(): TenantSurface
    {
        return TenantSurface::Portal;
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->portal->isAccount()) {
            return $this->forAccount($request, $next);
        }

        $customer = Auth::guard('customer')->user();

        try {
            $organization = $customer instanceof Customer ? $this->resolver->resolve(self::surface(), $customer) : null;
        } catch (ModelNotFoundException) {
            $organization = null;
        }

        if ($organization === null) {
            Auth::guard('customer')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect()->guest(route('portal.access'));
        }

        $this->context->setOrganization($organization);

        return $next($request);
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    private function forAccount(Request $request, Closure $next): Response
    {
        $code = $request->route('code');
        $business = $request->query(self::BUSINESS_PARAMETER);

        if (! is_string($code) && ! is_numeric($business)) {
            return $next($request);
        }

        $organization = is_string($code)
            ? $this->resolver->forLayawayCode(LayawayCode::normalize($code))
            : $this->resolver->forOrganizationId((int) $business);

        if ($this->portal->customerIn((int) $organization->getKey()) === null) {
            throw (new ModelNotFoundException)->setModel(Organization::class);
        }

        $this->context->setOrganization($organization);

        return $next($request);
    }
}
