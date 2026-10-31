<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogExpiredException;
use App\Domain\Chat\NoCustomerRecordException;
use App\Domain\Money\CurrencyDisplay;
use App\Domain\Subscription\CatalogProductLimitReachedException;
use App\Domain\Subscription\CreationSuspendedException;
use App\Domain\Subscription\PlanFeatureUnavailableException;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Tenancy\CannotDisableSelfException;
use App\Domain\Tenancy\InvitationNotPendingException;
use App\Domain\Tenancy\LastOwnerException;
use App\Exceptions\ExpiredInertiaSession;
use App\Http\Middleware\AllowedDuringSuspension;
use App\Http\Middleware\AssignVisitorId;
use App\Http\Middleware\EnforcePlanLimit;
use App\Http\Middleware\EnsureAccountSession;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureIsOwner;
use App\Http\Middleware\EnsureMonitorAccess;
use App\Http\Middleware\EnsureOrganizationIsActive;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsurePlatformUserIsActive;
use App\Http\Middleware\EnsurePortalSession;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\OperatesWithoutTenant;
use App\Http\Middleware\OutsideTenancy;
use App\Http\Middleware\RequiresSupportAccess;
use App\Http\Middleware\ResolveCatalogTenant;
use App\Http\Middleware\ResolvePortalTenant;
use App\Http\Middleware\ResolveStorefrontTenant;
use App\Http\Middleware\ResolveTenant;
use App\Support\CatalogShareLink;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Exceptions\RegisterErrorViewPaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [__DIR__.'/../routes/monitor.php', __DIR__.'/../routes/web.php'],
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'outside-tenancy']])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            AssignVisitorId::class,
        ]);

        $middleware->encryptCookies(except: [CurrencyDisplay::COOKIE, AssignVisitorId::COOKIE]);

        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveCatalogTenant::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveStorefrontTenant::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolvePortalTenant::class);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'catalog-tenant' => ResolveCatalogTenant::class,
            'storefront-tenant' => ResolveStorefrontTenant::class,
            'portal-tenant' => ResolvePortalTenant::class,
            'portal' => EnsurePortalSession::class,
            'account-session' => EnsureAccountSession::class,
            'without-tenant' => OperatesWithoutTenant::class,
            'platform-active' => EnsurePlatformUserIsActive::class,
            'support-access' => RequiresSupportAccess::class,
            'outside-tenancy' => OutsideTenancy::class,
            'owner' => EnsureIsOwner::class,
            'admin-active' => EnsureAdminIsActive::class,
            'organization-active' => EnsureOrganizationIsActive::class,
            'password-changed' => EnsurePasswordIsChanged::class,
            'during-suspension' => AllowedDuringSuspension::class,
            'plan-feature' => EnforcePlanLimit::class,
            'monitor' => EnsureMonitorAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (CatalogExpiredException $exception, Request $request): SymfonyResponse {
            return Inertia::render('catalog/CatalogExpired', $exception->toArray())
                ->toResponse($request)
                ->setStatusCode(CatalogExpiredException::STATUS);
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request): ?SymfonyResponse {
            if (! $request->isMethod('GET') || ! $request->is(CatalogShareLink::PREFIX.'/*') || $request->expectsJson()) {
                return null;
            }

            $neutral = $request->duplicate(server: ['REQUEST_URI' => '/'.CatalogShareLink::PREFIX]);

            return Inertia::render('catalog/CatalogUnavailable')
                ->toResponse($neutral)
                ->setStatusCode(404);
        });

        $exceptions->render(function (NoCustomerRecordException $exception, Request $request): SymfonyResponse {
            return $request->expectsJson()
                ? response()->json(['message' => $exception->getMessage()], NoCustomerRecordException::STATUS)
                : abort(NoCustomerRecordException::STATUS, $exception->getMessage());
        });

        $exceptions->render(function (LastOwnerException $exception): JsonResponse {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['role' => [$exception->getMessage()]],
            ], LastOwnerException::STATUS);
        });

        $exceptions->render(function (CannotDisableSelfException $exception): JsonResponse {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['member' => [$exception->getMessage()]],
            ], CannotDisableSelfException::STATUS);
        });

        $exceptions->render(function (InvitationNotPendingException $exception): JsonResponse {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['member' => [$exception->getMessage()]],
            ], InvitationNotPendingException::STATUS);
        });

        $planLimit = function (PlanLimitReachedException|CatalogProductLimitReachedException $exception, Request $request): SymfonyResponse {
            $body = [
                'message' => $exception->getMessage(),
                'limit' => $exception->toArray(),
                'upgradeUrl' => '/backoffice/suscripcion',
            ];

            if ($request->expectsJson()) {
                return response()->json(['errors' => ['plan' => [$exception->getMessage()]]] + $body, $exception::STATUS);
            }

            return back()->withErrors(['plan' => $exception->getMessage()])->with('planLimit', $body);
        };

        $exceptions->render(fn (PlanLimitReachedException $exception, Request $request): SymfonyResponse => $planLimit($exception, $request));
        $exceptions->render(fn (CatalogProductLimitReachedException $exception, Request $request): SymfonyResponse => $planLimit($exception, $request));

        $exceptions->render(function (PlanFeatureUnavailableException $exception, Request $request): SymfonyResponse {
            $body = [
                'message' => $exception->getMessage(),
                'feature' => $exception->toArray(),
                'upgradeUrl' => '/backoffice/suscripcion',
            ];

            if ($request->expectsJson() || ($request->isMethod('GET') && ! $request->header('X-Inertia'))) {
                return response()->json($body, PlanFeatureUnavailableException::STATUS);
            }

            return back()->withErrors(['plan' => $exception->getMessage()])->with('planFeature', $body);
        });

        $exceptions->render(function (InvalidSignatureException $exception, Request $request): ?SymfonyResponse {
            if (! $request->routeIs('exports.download')) {
                return null;
            }

            (new RegisterErrorViewPaths)();

            return response()->view('errors.expired-link', [], 403);
        });

        $exceptions->render(function (CreationSuspendedException $exception, Request $request): SymfonyResponse {
            if ($request->expectsJson() || ($request->isMethod('GET') && ! $request->header('X-Inertia'))) {
                return response()->json(['message' => $exception->getMessage()], CreationSuspendedException::STATUS);
            }

            return back()->withErrors(['plan' => $exception->getMessage()])->with('planBlocked', [
                'message' => $exception->getMessage(),
                'upgradeUrl' => '/backoffice/suscripcion',
            ]);
        });

        $exceptions->respond(new ExpiredInertiaSession);
    })->create();
