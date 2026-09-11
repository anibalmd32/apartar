<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tenancy\TenantContext;
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
        //
    }
}
