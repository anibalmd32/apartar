<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogExpiredException;
use App\Domain\Money\CurrencyDisplay;
use App\Domain\Subscription\CreationSuspendedException;
use App\Domain\Subscription\PlanFeatureUnavailableException;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Tenancy\CannotDisableSelfException;
use App\Domain\Tenancy\InvitationNotPendingException;
use App\Domain\Tenancy\LastOwnerException;
use App\Exceptions\ExpiredInertiaSession;
use App\Http\Middleware\AllowedDuringSuspension;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureIsOwner;
use App\Http\Middleware\EnsureOrganizationIsActive;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\OperatesWithoutTenant;
use App\Http\Middleware\OutsideTenancy;
use App\Http\Middleware\ResolveCatalogTenant;
use App\Http\Middleware\ResolveStorefrontTenant;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Endpoints internos que refresca el propio frontend (REQ-FX-002
        // RB-5). Van aparte de `web` porque no tienen sesión ni CSRF: son
        // lecturas públicas y sin estado, y meterlas en el grupo `web`
        // arrancaría una sesión por cada refresco de cada pestaña abierta.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // La preferencia de moneda la escribe el navegador al pulsar el
        // toggle, porque el cambio es puramente de cliente (REQ-FX-004 RB-6):
        // llega sin cifrar y el servidor no podría descifrarla. Sin esta
        // exclusión el valor se descartaría en cada petición y el primer
        // render volvería siempre al default —el parpadeo que RB-4 existe para
        // evitar, pero permanente—.
        //
        // Lo que guarda es cuál de dos monedas va primero: no identifica a
        // nadie ni autoriza nada.
        $middleware->encryptCookies(except: [CurrencyDisplay::COOKIE]);

        // El tenant se fija ANTES de que `SubstituteBindings` resuelva el
        // binding, y este orden es lo que hace verdadera la regla del `404`
        // de REQ-ORG-002 RB-6. Sin la prioridad explícita, un middleware de
        // ruta queda detrás del grupo `web` y el binding de un modelo del
        // tenant se resolvería sin organización fijada: no sería una fuga
        // —el global scope lanza— pero sería un 500 en lugar del 404 que la
        // regla exige, y con él la confirmación de que el recurso ajeno
        // existe.
        //
        // La superficie pública tiene el mismo problema y la misma solución,
        // y con más motivo: allí el modelo del binding **es** la fuente del
        // tenant (`{catalog:slug}`), así que sin la prioridad no habría forma
        // de resolverlo. Correr antes que el binding la deja también por
        // delante de `HandleInertiaRequests`, que es lo que hace que la tasa y
        // la zona horaria compartidas sean las del negocio del catálogo.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveCatalogTenant::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveStorefrontTenant::class);

        // Cada superficie declara de dónde sale su tenant (REQ-ORG-002 RB-4).
        // `catalog-tenant` lo saca del slug del catálogo (`T-033`);
        // `storefront-tenant`, del de la organización, y solo si su vitrina
        // está activada (`T-036`);
        // `ResolvePortalTenant` entra con H3, cuando exista `Customer`.
        //
        // `outside-tenancy` no resuelve nada: declara que la ruta no
        // pertenece a ninguna superficie. La suite de REQ-QAX-008 exige que
        // toda ruta nuestra diga una cosa o la otra, porque no declarar nada
        // es indistinguible de un olvido.
        // `owner` cierra un grupo de rutas al dueño (REQ-ORG-003 RB-4). No
        // sustituye a la policy de cada operación: protege el camino, no el
        // modelo concreto sobre el que se acaba operando.
        // Los tres de REQ-BOF-001 se aplican en este orden y cada uno da por
        // hecho lo que resolvió el anterior: `admin-active` mira la persona,
        // `organization-active` su negocio, `password-changed` lo que le queda
        // por hacer antes de poder navegar. `during-suspension` no comprueba
        // nada: declara qué rutas sobreviven a una suspensión, y lo que no se
        // declara queda bloqueado (REQ-ORG-008 RB-3).
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'catalog-tenant' => ResolveCatalogTenant::class,
            'storefront-tenant' => ResolveStorefrontTenant::class,
            'without-tenant' => OperatesWithoutTenant::class,
            'outside-tenancy' => OutsideTenancy::class,
            'owner' => EnsureIsOwner::class,
            'admin-active' => EnsureAdminIsActive::class,
            'organization-active' => EnsureOrganizationIsActive::class,
            'password-changed' => EnsurePasswordIsChanged::class,
            'during-suspension' => AllowedDuringSuspension::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // REQ-CAT-021 SP-3: el catálogo expirado es una **página**, no un
        // error. Quien la ve pulsó un enlace bueno que llegó tarde, y el `410`
        // lo dice sin hacerle creer que lo copió mal. El porqué del código
        // está escrito en la clase.
        $exceptions->render(function (CatalogExpiredException $exception, Request $request): SymfonyResponse {
            return Inertia::render('catalog/CatalogExpired', $exception->toArray())
                ->toResponse($request)
                ->setStatusCode(CatalogExpiredException::STATUS);
        });

        // REQ-ORG-003 SP-2: quedarse sin el último owner es 422 y no 403. El
        // intento es legítimo y está bien formado —suele hacerlo el propio
        // owner, que lo puede todo—; lo que no se puede es aceptarlo. El
        // cuerpo lleva `errors` para que Inertia lo muestre junto al campo,
        // como cualquier otra validación.
        $exceptions->render(function (LastOwnerException $exception): JsonResponse {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['role' => [$exception->getMessage()]],
            ], LastOwnerException::STATUS);
        });

        // REQ-ORG-005 SP-1: autodesactivarse es 422 por lo mismo que lo
        // anterior. Quien lo intenta es el owner, que lo puede todo; lo que no
        // se puede es aceptarlo.
        $exceptions->render(function (CannotDisableSelfException $exception): JsonResponse {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['member' => [$exception->getMessage()]],
            ], CannotDisableSelfException::STATUS);
        });

        // REQ-ORG-004 RB-6: revocar o reenviar una invitación que ya se aceptó
        // llega tarde, no llega mal.
        $exceptions->render(function (InvitationNotPendingException $exception): JsonResponse {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['member' => [$exception->getMessage()]],
            ], InvitationNotPendingException::STATUS);
        });

        // REQ-ORG-004 SP-1 y REQ-SUB-004 RB-5: el 422 del límite **lleva
        // dentro el plan que lo desbloquea**. Sin ese cuerpo, la pantalla solo
        // podría decir «no puedes», que deja a quien lo lee donde estaba; con
        // él enlaza a Suscripción sabiendo qué ofrecer, y sin lógica propia
        // (RB-1).
        $exceptions->render(function (PlanLimitReachedException $exception): JsonResponse {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['email' => [$exception->getMessage()]],
                'limit' => $exception->toArray(),
                // La pantalla de suscripción llega con su hito; la URL ya es
                // la definitiva de [05 §7.4], igual que en el aviso de
                // suspensión de `BackofficeLayout`, para que el enlace esté
                // completo el día que exista.
                'upgradeUrl' => '/backoffice/suscripcion',
            ], PlanLimitReachedException::STATUS);
        });

        // REQ-SUB-004 SP-3: una función que el plan no incluye es 403 con el
        // motivo, nunca 404. Ocultar que existe impide entender que basta con
        // subir de plan.
        $exceptions->render(function (PlanFeatureUnavailableException $exception): JsonResponse {
            return response()->json([
                'message' => $exception->getMessage(),
                'feature' => $exception->toArray(),
            ], PlanFeatureUnavailableException::STATUS);
        });

        // REQ-SUB-004 RB-8: suspendida no se crea nada. Es 403 y no 422 porque
        // no falta sitio, falta estar al día.
        $exceptions->render(function (CreationSuspendedException $exception): JsonResponse {
            return response()->json([
                'message' => $exception->getMessage(),
            ], CreationSuspendedException::STATUS);
        });

        // REQ-BOF-001 SP-6: la petición de Inertia con la sesión caducada se
        // responde con un 409 que fuerza la recarga al acceso. El porqué está
        // escrito en la clase.
        $exceptions->respond(new ExpiredInertiaSession);
    })->create();
