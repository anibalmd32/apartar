<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Platform\SupportAccessRequiredException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\PlatformUser;
use App\Queries\Platform\SupportGrants;
use App\Support\Auditing\PlatformAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class RequiresSupportAccess
{
    public function __construct(
        private readonly SupportGrants $grants,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws SupportAccessRequiredException
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw SupportAccessRequiredException::readOnly();
        }

        $organization = $request->route('organization');
        $operator = Auth::guard('platform')->user();

        if (! $organization instanceof Organization || ! $operator instanceof PlatformUser) {
            abort(404);
        }

        if ($this->grants->activeFor((int) $operator->getKey(), (int) $organization->getKey()) === null) {
            throw SupportAccessRequiredException::noGrant($organization);
        }

        PlatformAudit::record(PlatformAudit::SUPPORT_VIEWED, 'Consultó '.($request->route()?->getName() ?? $request->path()).'.', $operator, (int) $organization->getKey(), [
            'route' => $request->route()?->getName(),
            'query' => $request->query(),
        ]);

        return $this->context->runFor($organization, fn (): Response => $next($request));
    }
}
