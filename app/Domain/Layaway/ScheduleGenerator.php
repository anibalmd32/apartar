<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Construye el cronograma de un apartado a partir de sus condiciones
 * congeladas (`REQ-LAY-004`).
 *
 * **Es dominio puro.** Sin `now()`, sin `config()`, sin `TenantContext` y sin
 * consultas: las condiciones, el instante de activación y la zona horaria del
 * negocio llegan por parámetro. Es lo que permite probarlo con quinientos
 * casos en un segundo, y lo que impide que un cronograma dependa del día o del
 * servidor en que se generó. Materializarlo en `installments` es de
 * `ActivateLayawayAction` (`T-046`).
 *
 * ## Los importes
 *
 * Son los de `LayawayTerms::financingPlan()`, que reparte el financiado del
 * **apartado entero** con `Money::allocateEvenly()`: partes iguales y el
 * residuo en la **última** (RB-2). Aquí no hay una segunda división, ni se
 * multiplica la cuota de una unidad por la cantidad (ver `LayawayTerms`).
 *
 * ## Las fechas
 *
 * La cuota N vence a `activatedAt + N × frequencyDays` **días de calendario**
 * en la zona de la organización (RB-3), así que la cuota 1 vence a los
 * `frequencyDays` de la activación y no el mismo día: activado el 10 con
 * frecuencia 15, la primera es el 25.
 *
 * El día de partida es el de la activación **en la zona del negocio**, no en
 * UTC ni en la del servidor (SP-7): las 22:00 del 9 en Caracas ya son el 10 en
 * UTC, y ese día de diferencia se arrastraría a todas las cuotas.
 *
 * **No se desplaza nada por domingo ni por feriado** (RB-4), y la frecuencia
 * es en **días, no en meses**: por eso el 29 de febrero y los fines de mes no
 * son casos especiales (SP-4). Un apartado activado el 31 de enero de 2027 con
 * cuotas cada 30 días tiene la primera el 2 de marzo, y es correcto: nadie ha
 * prometido «el mismo día de cada mes», que está fuera de alcance.
 *
 * Los días se suman sobre el reloj de pared de la zona, no sobre segundos:
 * en una zona con horario de verano, un día de 23 o 25 horas sigue siendo un
 * día, y la fecha no se corre.
 */
final class ScheduleGenerator
{
    /**
     * Rangos de RB-7. **El censo vive aquí**: `CoherentFinancingTerms` los lee
     * para validar el producto en el backoffice, y `LayawayTerms` para
     * congelar las condiciones del apartado.
     */
    public const MIN_INSTALLMENTS = 1;

    public const MAX_INSTALLMENTS = 36;

    public const MIN_FREQUENCY_DAYS = 1;

    public const MAX_FREQUENCY_DAYS = 90;

    /**
     * Construye el cronograma a partir de las condiciones congeladas.
     *
     * El importe financiado se reparte en partes iguales; el residuo de la
     * división entera se acumula en la última cuota. Un financiado cero
     * devuelve un cronograma vacío (SP-2).
     *
     * No lanza por rangos: un `LayawayTerms` fuera de rango no se puede
     * construir (ver `InvalidScheduleException`).
     */
    public function generate(
        LayawayTerms $terms,
        CarbonImmutable $activatedAt,
        DateTimeZone $organizationTimezone,
    ): AmortizationSchedule {
        $amounts = $terms->financingPlan()?->installments() ?? [];
        $activationDay = $activatedAt->setTimezone($organizationTimezone)->startOfDay();

        $installments = [];

        foreach ($amounts as $index => $amount) {
            $number = $index + 1;

            $installments[] = new ScheduledInstallment(
                $number,
                $activationDay->addDays($number * $terms->frequencyDays)->startOfDay(),
                $amount,
            );
        }

        return new AmortizationSchedule($installments, $terms->unitPrice->currency);
    }
}
