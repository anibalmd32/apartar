<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Telemetry\VisitorIdentity;
use App\Models\Customer;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignVisitorId
{
    public const COOKIE = 'apartar_vid';

    public const MINUTES = 525600;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $visitorId = self::of($request);

        if ($visitorId === null) {
            $visitorId = Str::uuid7()->toString();
            Cookie::queue(Cookie::make(self::COOKIE, $visitorId, self::MINUTES, '/', null, null, false, false, 'lax'));
            $request->cookies->set(self::COOKIE, $visitorId);
        }

        $response = $next($request);

        $this->rememberIdentity($request, $visitorId);

        return $response;
    }

    public static function of(Request $request): ?string
    {
        $value = $request->cookies->get(self::COOKIE);

        return is_string($value) && Str::isUuid($value) ? strtolower($value) : null;
    }

    private function rememberIdentity(Request $request, string $visitorId): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $account = Auth::guard('account');
        $accountId = $account instanceof SessionGuard ? $request->session()->get($account->getName()) : null;

        $customerGuard = Auth::guard('customer');
        $customer = $customerGuard instanceof SessionGuard && $customerGuard->hasUser() ? $customerGuard->user() : null;

        if ($accountId === null && ! $customer instanceof Customer) {
            return;
        }

        VisitorIdentity::remember(
            $visitorId,
            is_numeric($accountId) ? (int) $accountId : null,
            $customer instanceof Customer ? (int) $customer->organization_id : null,
            $customer instanceof Customer ? (int) $customer->getKey() : null,
        );
    }
}
