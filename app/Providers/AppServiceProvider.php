<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\TenantAwareUserProvider;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Auth;
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
    }
}
