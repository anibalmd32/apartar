<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\TenantAwareUserProvider;
use App\Domain\Account\PortalContext;
use App\Domain\Tenancy\TenantContext;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // `TenantContext` es singleton por ciclo de vida ([05 §5]): una
        // petición o un job tienen exactamente una organización activa. Si el
        // contenedor devolviese instancias distintas, el global scope leería
        // un contexto y el middleware escribiría en otro, y el aislamiento
        // dejaría de existir sin que nada fallase.
        $this->app->singleton(TenantContext::class);

        // `Settings` también es singleton, y por un motivo distinto del de
        // arriba: memoriza lo ya resuelto **por organización** dentro de la
        // petición (`REQ-ORG-006` RB-3). Con instancias sueltas, cada
        // inyección volvería a Redis y el memo no serviría de nada; con una
        // sola, la caché de proceso vive donde se puede probar que no filtra
        // entre organizaciones, que es justo lo que comprueba
        // `SettingsCacheLeakTest`.
        $this->app->singleton(Settings::class, fn (Application $app): Settings => new Settings(
            $app->make(TenantContext::class),
            $app->make(CacheFactory::class)->store(),
        ));

        // `PortalContext` es *scoped*: se lee de la sesión de **esta**
        // petición y muere con ella. Un `singleton` sobreviviría en el worker
        // y respondería con la ficha de la petición anterior.
        $this->app->scoped(PortalContext::class, fn (): PortalContext => PortalContext::fromSession());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Driver del proveedor de usuarios del guard `web`. El login resuelve
        // al usuario antes de saber su organización, así que consulta `users`
        // sin el filtro de tenancy; el resto de `User::query()` sí lo lleva.
        Auth::provider('tenant-aware', function (Application $app, array $config): TenantAwareUserProvider {
            /** @var class-string<Model> $model */
            $model = $config['model'];

            return new TenantAwareUserProvider($app->make(Hasher::class), $model);
        });

        // Las tres policies del backoffice —`OrganizationPolicy`, `UserPolicy`
        // y `SubscriptionPolicy`— las descubre Laravel por convención desde su
        // modelo, así que no se registra ninguna aquí. `SubscriptionPolicy`
        // estuvo como gates con nombre mientras `Subscription` no existía
        // (`T-009`); con el modelo creado en `T-011` dejó de hacer falta.

        $this->registerRateLimiters();
    }

    /**
     * Límites de peticiones con nombre.
     *
     * `REQ-ORG-001` RB-8: el registro es abierto —sin invitación ni lista de
     * espera— y se acota a **cinco por hora y por IP**. Es un límite de
     * negocio y no de rendimiento: alguien que abre negocios en serie no está
     * probando el producto.
     *
     * Va con nombre y no como `throttle:5,60` en la ruta para que el número
     * viva junto a su motivo y se pueda citar desde un test.
     *
     * `apartados` (`T-045b`): el envío del formulario de apartado es público,
     * sin sesión, y **reserva stock durante 72 horas**. Sin techo, un solo
     * cliente con un bucle dejaría agotado el inventario de un negocio sin
     * pagar nada. Diez por minuto y por IP no los alcanza nadie que esté
     * apartando de verdad —ni con dobles clics, que la idempotencia devuelve
     * sin crear nada— y convierte el bucle en un goteo.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('registro', fn (Request $request) => Limit::perHour(5)->by($request->ip() ?? 'sin-ip'));
        RateLimiter::for('apartados', fn (Request $request) => Limit::perMinute(self::LAYAWAY_SUBMISSIONS_PER_MINUTE)->by($request->ip() ?? 'sin-ip'));
        RateLimiter::for('comprobantes', fn (Request $request) => Limit::perHour(self::RECEIPT_SUBMISSIONS_PER_HOUR)
            ->by('ficha:'.(Auth::guard('customer')->id() ?? $request->ip() ?? 'sin-ip')));
        RateLimiter::for('reenvio-codigo', fn (Request $request) => Limit::perHour(self::CODE_RESENDS_PER_HOUR)
            ->by('apartado:'.self::routeKeyOf($request->route('layaway'))));
    }

    /**
     * Reenvíos del código de un apartado por hora y **por apartado**
     * (`REQ-IDN-008` SP-5, `T-065`). El correo sale a nombre del negocio al
     * buzón del cliente: tres cubren a quien lo perdió y lo vuelve a perder;
     * sin techo, un clic repetido le llenaría la bandeja.
     */
    public const CODE_RESENDS_PER_HOUR = 3;

    private static function routeKeyOf(mixed $bound): string
    {
        return match (true) {
            $bound instanceof Model => (string) $bound->getKey(),
            is_string($bound) => $bound,
            default => 'sin-apartado',
        };
    }

    /**
     * Comprobantes por hora y **por ficha** de cliente (`REQ-PAY-001` RB-12,
     * `T-059`). Por ficha y no por IP: la ruta va detrás de la sesión del
     * portal, y una familia que comparte la conexión del hogar no se reparte
     * el cupo. Diez cubre a quien reintenta un comprobante rechazado varias
     * veces; un bucle llenaría la bandeja del negocio de capturas.
     */
    public const RECEIPT_SUBMISSIONS_PER_HOUR = 10;

    /** Envíos del formulario de apartado por minuto y por IP (`T-045b`). */
    public const LAYAWAY_SUBMISSIONS_PER_MINUTE = 10;
}
