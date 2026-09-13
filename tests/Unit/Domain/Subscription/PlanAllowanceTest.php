<?php

declare(strict_types=1);

use App\Domain\Subscription\PlanAllowance;
use App\Domain\Subscription\UsageMetric;

/**
 * Las cifras de la matriz que **no son techos que se alcancen**.
 *
 * La prueba para saber dónde va una fila de [03 §5] es si tiene sentido decir
 * «te quedan 3»: de los productos sí —y por eso son `UsageMetric`—, de los
 * días de retención de telemetría no. Tampoco son de sí o no, así que no caben
 * en `PlanFeature`. Viven en el mismo `features` JSON y se leen por este enum
 * para que nadie las busque con una cadena escrita a mano.
 */
it('declara las dos concesiones numéricas de la matriz', function (): void {
    $values = array_map(fn (PlanAllowance $allowance): string => $allowance->value, PlanAllowance::cases());

    expect($values)->toBe(['chat_history_days', 'telemetry_retention_days']);
});

/**
 * Y no se confunden con las métricas: ninguna de las dos aparece como algo que
 * la organización consuma.
 */
it('no se solapa con las métricas de consumo', function (): void {
    $metrics = array_map(fn (UsageMetric $metric): string => $metric->value, UsageMetric::cases());

    foreach (PlanAllowance::cases() as $allowance) {
        expect($metrics)->not->toContain($allowance->value);
    }
});
