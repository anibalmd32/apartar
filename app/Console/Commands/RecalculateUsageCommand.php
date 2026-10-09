<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Subscription\UsageDrift;
use App\Domain\Subscription\UsageMetric;
use App\Jobs\RecalculateUsage;
use App\Models\Organization;
use Illuminate\Console\Command;

/**
 * `php artisan subscriptions:recalculate-usage [--organization=] [--metric=]`
 * (`REQ-SUB-003` RB-4, SP-1, `T-066`).
 *
 * Ejecuta en el momento lo que el job hace cada noche: mide de verdad, corrige
 * los contadores que no cuadren e imprime cuáles eran. A diferencia de
 * `layaways:reconcile` **siempre corrige**: el contador es una cifra derivada
 * —los datos de negocio no se tocan nunca (SP-1)—, y dejarla mal a sabiendas
 * es dejar que el plan se haga cumplir contra una ficción.
 *
 * Termina con éxito aunque haya corregido derivas: corregir es su trabajo. Una
 * deriva es un aviso para quien la lea, y por eso sale en la tabla y en el
 * registro (`usage_counter.drift`).
 */
final class RecalculateUsageCommand extends Command
{
    protected $signature = 'subscriptions:recalculate-usage
        {--organization= : Acota a una organización, por su id}
        {--metric= : Acota a una métrica (users, catalogs, products, active_layaways, storage_mb, payment_accounts)}';

    protected $description = 'Recalcula desde cero el consumo de cada organización y corrige los contadores (REQ-SUB-003)';

    public function handle(): int
    {
        $organizationId = $this->organizationOption();
        $metric = $this->metricOption();

        if ($organizationId === false || $metric === false) {
            return self::FAILURE;
        }

        $result = app()->call([new RecalculateUsage($organizationId, $metric), 'handle']);

        if ($result === null) {
            $this->error('Hay otra pasada del recálculo en curso: vuelve a intentarlo cuando termine.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d organizaciones recalculadas; %d contadores descuadrados, ya corregidos.',
            $result['organizations'],
            count($result['drifts']),
        ));

        if ($result['drifts'] !== []) {
            $this->table(['Organización', 'Métrica', 'Decía', 'Mide'], array_map(fn (UsageDrift $drift): array => [
                $drift->organizationId,
                $drift->metric->value,
                $drift->stored,
                $drift->measured,
            ], $result['drifts']));
        }

        return self::SUCCESS;
    }

    /**
     * @return int|false|null `null` sin la opción; `false` si no es una organización
     */
    private function organizationOption(): int|false|null
    {
        $organization = $this->option('organization');

        if ($organization === null) {
            return null;
        }

        // Solo dígitos, como en `layaways:reconcile`: `is_numeric` aceptaría
        // «1.5» y recalcularía la organización 1 sin decir nada.
        $organizationId = ctype_digit((string) $organization) ? (int) $organization : 0;

        if (! Organization::query()->whereKey($organizationId)->exists()) {
            $this->error(sprintf('No existe la organización «%s».', (string) $organization));

            return false;
        }

        return $organizationId;
    }

    /**
     * @return UsageMetric|false|null `null` sin la opción; `false` si no es una métrica
     */
    private function metricOption(): UsageMetric|false|null
    {
        $metric = $this->option('metric');

        if ($metric === null) {
            return null;
        }

        $parsed = UsageMetric::tryFrom((string) $metric);

        if ($parsed === null) {
            $this->error(sprintf('No existe la métrica «%s».', (string) $metric));

            return false;
        }

        return $parsed;
    }
}
