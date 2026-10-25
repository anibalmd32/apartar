<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Subscription\UsageDrift;
use App\Domain\Subscription\UsageMetric;
use App\Jobs\RecalculateUsage;
use App\Models\Organization;
use Illuminate\Console\Command;

final class RecalculateUsageCommand extends Command
{
    protected $signature = 'subscriptions:recalculate-usage
        {--organization= : Acota a una organización, por su id}
        {--metric= : Acota a una métrica (users, catalogs, products, active_layaways, storage_mb, payment_accounts)}';

    protected $description = 'Recalcula desde cero el consumo de cada organización y corrige los contadores';

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

    private function organizationOption(): int|false|null
    {
        $organization = $this->option('organization');

        if ($organization === null) {
            return null;
        }

        $organizationId = ctype_digit((string) $organization) ? (int) $organization : 0;

        if (! Organization::query()->whereKey($organizationId)->exists()) {
            $this->error(sprintf('No existe la organización «%s».', (string) $organization));

            return false;
        }

        return $organizationId;
    }

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
