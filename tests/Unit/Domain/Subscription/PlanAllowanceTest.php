<?php

declare(strict_types=1);

use App\Domain\Subscription\PlanAllowance;
use App\Domain\Subscription\UsageMetric;

it('declara las dos concesiones numéricas de la matriz', function (): void {
    $values = array_map(fn (PlanAllowance $allowance): string => $allowance->value, PlanAllowance::cases());

    expect($values)->toBe(['chat_history_days', 'telemetry_retention_days']);
});

it('no se solapa con las métricas de consumo', function (): void {
    $metrics = array_map(fn (UsageMetric $metric): string => $metric->value, UsageMetric::cases());

    foreach (PlanAllowance::cases() as $allowance) {
        expect($metrics)->not->toContain($allowance->value);
    }
});
