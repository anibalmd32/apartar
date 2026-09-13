<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

/**
 * Lo que el plan concede con un número que **no es un techo que se alcance**.
 *
 * La matriz de [03 §5] tiene tres filas que llevan cifra y no son límites de
 * consumo: el historial de chat visible y la retención de telemetría. Nadie
 * «gasta» días de retención, así que no son `UsageMetric` —no tiene sentido
 * decir «te quedan 3»— pero tampoco son booleanas, así que no caben en
 * `PlanFeature`.
 *
 * Viven en el mismo `plans.features` que las funciones, junto a ellas, porque
 * son lo mismo desde el punto de vista del esquema: algo que el plan declara y
 * que no se compara contra ningún contador. Este enum existe para que nadie
 * las lea con una cadena escrita a mano.
 *
 * **`null` significa ilimitado**, igual que en las columnas `max_*`
 * (`REQ-SUB-001` RB-2): en Pro y Business el historial de chat no tiene
 * ventana, y eso se dice con `null`, nunca con `0`.
 */
enum PlanAllowance: string
{
    /**
     * Días de historial de chat visibles en la bandeja del negocio
     * (`REQ-CHT-002` RB-8).
     *
     * En Free son 30. Los mensajes **no se borran**: dejan de mostrarse, y al
     * subir de plan reaparecen íntegros.
     */
    case ChatHistoryDays = 'chat_history_days';

    /**
     * Días que se conservan los eventos de telemetría de la organización
     * (`REQ-TLM-005` RB-6).
     */
    case TelemetryRetentionDays = 'telemetry_retention_days';
}
