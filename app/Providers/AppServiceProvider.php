<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\TenantAwareUserProvider;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Hashing\Hasher;
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
            /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
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
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('registro', fn (Request $request) => Limit::perHour(5)->by($request->ip() ?? 'sin-ip'));
    }
}
