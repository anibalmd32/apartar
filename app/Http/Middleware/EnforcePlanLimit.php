<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class EnforcePlanLimit
{
    public function __construct(private readonly LimitEnforcer $limits) {}

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws InvalidArgumentException
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $declared = PlanFeature::tryFrom($feature);

        if ($declared === null) {
            throw new InvalidArgumentException(sprintf('plan-feature:%s no es una función de plan.', $feature));
        }

        $this->limits->assertHasFeature($declared);

        return $next($request);
    }
}
