<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;

/**
 * Le da a una organización la suscripción que en producción siempre tiene
 * (`REQ-SUB-002` RB-1), con un plan **sin techos** y fuera del escaparate.
 *
 * Desde `T-067`, crear un producto, publicar un catálogo o componerlo pregunta
 * al plan (`LimitEnforcer`), y sin suscripción eso lanza —que es lo correcto:
 * una organización sin suscripción es una base a medio sembrar—. Los tests
 * que no van de límites usan este plan para que ninguno se interponga; los que
 * sí, siembran el suyo con su techo.
 */
trait SubscribesWithoutLimits
{
    /**
     * @param  Organization|null  $organization  la activa si no se dice
     */
    protected function subscribeWithoutLimits(?Organization $organization = null): void
    {
        $context = app(TenantContext::class);
        $organization ??= $context->organization();

        $context->runFor($organization, function (): void {
            if (Subscription::query()->exists()) {
                return;
            }

            Subscription::factory()->forPlan(Plan::factory()->unlimited()->hidden()->withEveryFeature()->create())->create();
        });
    }
}
