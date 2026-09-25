<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\UsageMetric;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La página de precios (`REQ-SUB-001`).
 *
 * ## Se renderiza desde `plans`, y de ningún otro sitio (RB-8)
 *
 * Es la misma fuente de verdad que el backoffice: los límites, las funciones y
 * el precio salen de la fila del plan, y la pantalla no tiene ninguna cifra ni
 * ninguna tabla escrita a mano. Ajustar un plan es un `UPDATE` (RB-1) y esta
 * página lo enseña en la siguiente visita, sin desplegar.
 *
 * Una sola consulta: `Plan::public()`, que deja fuera los planes negociados
 * (RB-6) y los ordena por `position`. Un plan despublicado no sale aquí, y sus
 * organizaciones no se enteran (SP-3): esta página no toca suscripciones.
 *
 * ## `null` viaja como `null` (RB-2, SP-2)
 *
 * `limitFor()` devuelve `?int` para que «ilimitado» llegue entero hasta la
 * vista, y aquí no se convierte en nada: ni `0`, ni `PHP_INT_MAX`, ni la
 * palabra. Quien lo escribe es `formatLimit()` en `lib/plan.ts`, que es donde
 * está probado. Convertirlo antes sería dejar dos sitios que deciden cómo se
 * dice «sin techo».
 *
 * ## Hoy los tres planes dicen «Gratis», y es la verdad de la base
 *
 * `REQ-SUB-001` RB-3b: los precios de Pro y Business están sin decidir y
 * `T-011` los sembró a `0` en vez de inventarlos. Esta página enseña lo que
 * hay; lo que impide que eso se despliegue sin que nadie lo mire es
 * `PricingPageTest::test_the_paid_plans_are_still_unpriced()`, que se pone
 * rojo el día que se fijen.
 */
final class PricingController extends Controller
{
    /**
     * Los límites de la matriz de RB-3, en el orden en que se leen.
     *
     * Seis son métricas de consumo y una no: los productos por catálogo se
     * cuentan por catálogo y no tienen `UsageMetric` (ver su docblock). Por eso
     * la lista es de claves y no de casos del enum.
     *
     * @var list<string>
     */
    private const LIMITS = [
        'users',
        'catalogs',
        'products',
        'products_per_catalog',
        'active_layaways',
        'storage_mb',
        'payment_accounts',
    ];

    public function __invoke(): Response
    {
        $plans = Plan::query()
            ->public()
            ->get()
            ->map(fn (Plan $plan): array => $this->planView($plan))
            ->values()
            ->all();

        return Inertia::render('marketing/Pricing', ['plans' => $plans]);
    }

    /**
     * Un plan tal como se compara.
     *
     * @return array{code: string, name: string, priceLabel: string, isFree: bool, limits: list<array{key: string, label: string, limit: int|null}>, features: list<string>}
     */
    private function planView(Plan $plan): array
    {
        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'priceLabel' => $this->priceLabel($plan),
            'isFree' => $plan->price_cents === 0,
            'limits' => array_map(fn (string $key): array => $this->limit($plan, $key), self::LIMITS),
            // SP-1 · sin `features` la lista sale vacía, no con un error:
            // `enabledFeatures()` ya lo resuelve así.
            'features' => array_map(
                fn (PlanFeature $feature): string => $this->featureLabel($feature),
                $plan->enabledFeatures(),
            ),
        ];
    }

    /**
     * El precio como se lee en la tarjeta.
     *
     * `0` es «Gratis»: es lo que significa la cifra, para Free y para
     * cualquier plan que la tenga. Ninguna excepción por plan, ni aquí ni en
     * la pantalla (RB-1). El intervalo solo se escribe cuando se sabe decir;
     * uno que no se reconoce deja la cifra sola en vez de inventarle un
     * periodo.
     */
    private function priceLabel(Plan $plan): string
    {
        if ($plan->price_cents === 0) {
            return 'Gratis';
        }

        $amount = MoneyFormatter::format(new Money($plan->price_cents));

        return match ($plan->interval) {
            'monthly' => $amount.' al mes',
            'yearly' => $amount.' al año',
            default => $amount,
        };
    }

    /**
     * Un límite con su nombre, concordado con la cifra.
     *
     * @return array{key: string, label: string, limit: int|null}
     */
    private function limit(Plan $plan, string $key): array
    {
        if ($key === 'products_per_catalog') {
            $limit = $plan->maxProductsPerCatalog();

            return [
                'key' => $key,
                'label' => $limit === 1 ? 'producto por catálogo' : 'productos por catálogo',
                'limit' => $limit,
            ];
        }

        $metric = UsageMetric::from($key);
        $limit = $plan->limitFor($metric);

        return [
            'key' => $key,
            // Sin techo, en plural: la pantalla compone «usuarios: ilimitado».
            'label' => $metric->label($limit ?? 2),
            'limit' => $limit,
        ];
    }

    /**
     * Cómo se llama cada función para quien todavía no es cliente.
     *
     * El `match` no lleva `default`: una séptima función tiene que decir aquí
     * cómo se llama, y PHPStan no deja olvidarlo.
     */
    private function featureLabel(PlanFeature $feature): string
    {
        return match ($feature) {
            PlanFeature::Branding => 'Tu logo y tu color, sin el sello de Apartar',
            PlanFeature::ExcelExport => 'Exportación a Excel',
            PlanFeature::WebPush => 'Notificaciones push',
            PlanFeature::SubscriberDigest => 'Resumen de novedades para quienes siguen tus catálogos',
            PlanFeature::FullAnalytics => 'Analítica completa',
            PlanFeature::CustomDomain => 'Dominio propio',
        };
    }
}
