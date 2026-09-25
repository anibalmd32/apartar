<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use App\Domain\Catalog\FinancingPlan;
use App\Domain\Money\Money;
use InvalidArgumentException;

/**
 * Las condiciones comerciales congeladas al crear el apartado
 * (`REQ-LAY-002`, `REQ-LAY-004`).
 *
 * Es lo que el cliente aceptó al pulsar «Apartar»: precio y cuota inicial
 * **por unidad**, la cantidad, y el plan de cuotas. A partir de aquí el
 * apartado no vuelve a leer `products` para nada financiero (RB-1).
 *
 * ## Reparte el total, nunca multiplica un reparto
 *
 * `FinancingPlan` reparte el financiado de **una** unidad, que es lo que
 * enseña la ficha del producto. Lo tentador con `quantity > 1` es multiplicar
 * esas cuotas por la cantidad, y da otra cifra: `allocateEvenly()` reparte con
 * división entera y deja el resto en la última parte, así que **no es
 * lineal**. $50 por unidad en 3 cuotas son `1666, 1666, 1668`; por dos da
 * `3332, 3332, 3336`. Los $100 del apartado en 3 cuotas son `3333, 3333,
 * 3334`. Un importe repartido es un resultado, no un ingrediente.
 *
 * Por eso esta clase **no** deriva sus cuotas de las de una unidad:
 * `financingPlan()` construye el `FinancingPlan` del **apartado entero**
 * —total y cuota inicial ya multiplicados— y el cronograma sale de ahí.
 *
 * ## Una sola resta y un solo reparto en el sistema
 *
 * `financedAmount()` y las cuotas los calcula `FinancingPlan`, que es la
 * misma clase que calcula la cuota de la ficha, de la card y de la
 * previsualización del backoffice. Con `quantity = 1` las entradas son las del
 * producto y el resultado es el mismo por construcción, y hay un test que lo
 * afirma importe a importe. La diferencia entre las dos clases es de qué se
 * ocupan, no de cómo calculan: `FinancingPlan` es un plan que se puede pintar
 * —con financiado mayor que cero—, y esto son unas condiciones que se pueden
 * congelar, incluidas las que no dejan nada por financiar (SP-2).
 *
 * ## No existe un `LayawayTerms` que no produzca un cronograma
 *
 * Los rangos de RB-7 se comprueban aquí y no en `ScheduleGenerator::generate()`
 * (ver `InvalidScheduleException`): unas condiciones fuera de rango fallan al
 * crear el apartado, no al activarlo con la inicial ya pagada.
 */
final readonly class LayawayTerms
{
    /**
     * @param  Money  $unitPrice  precio de una unidad
     * @param  Money  $downPayment  cuota inicial de una unidad; entre cero y el precio
     * @param  int  $quantity  unidades apartadas, al menos una
     * @param  int  $installmentsCount  cuotas del financiado, sin multiplicar por la cantidad (RB-3)
     * @param  int  $frequencyDays  días entre cuotas, sin multiplicar por la cantidad (RB-3)
     *
     * @throws InvalidScheduleException si las cuotas o la frecuencia están fuera de rango (RB-7)
     * @throws InvalidArgumentException si la aritmética no tiene sentido
     */
    public function __construct(
        public Money $unitPrice,
        public Money $downPayment,
        public int $quantity,
        public int $installmentsCount,
        public int $frequencyDays,
    ) {
        if ($installmentsCount < ScheduleGenerator::MIN_INSTALLMENTS || $installmentsCount > ScheduleGenerator::MAX_INSTALLMENTS) {
            throw InvalidScheduleException::installmentsOutOfRange($installmentsCount);
        }

        if ($frequencyDays < ScheduleGenerator::MIN_FREQUENCY_DAYS || $frequencyDays > ScheduleGenerator::MAX_FREQUENCY_DAYS) {
            throw InvalidScheduleException::frequencyOutOfRange($frequencyDays);
        }

        // Estas dos protegen la aritmética, no la regla de negocio: la
        // cantidad la valida el formulario (`REQ-LAY-001` RB-5) y la inicial
        // `CoherentFinancingTerms` al guardar el producto.
        if ($quantity < 1) {
            throw new InvalidArgumentException(sprintf(
                'Un apartado es de al menos una unidad, y se pidieron %d. Lo valida el formulario antes '
                .'(REQ-LAY-001 RB-5): si esto lanza, alguien congeló las condiciones sin pasar por él.',
                $quantity,
            ));
        }

        // `subtract()` además comprueba que las dos cifras son de la misma
        // moneda.
        if ($downPayment->isNegative() || $unitPrice->subtract($downPayment)->isNegative()) {
            throw new InvalidArgumentException(
                'La cuota inicial va de cero al precio de la unidad. Por encima, el financiado sería negativo '
                .'y las cuotas también; CoherentFinancingTerms lo impide al guardar el producto.'
            );
        }
    }

    /**
     * El precio del apartado: `unit_price × quantity` (RB-2).
     */
    public function total(): Money
    {
        return $this->unitPrice->multiply($this->quantity);
    }

    /**
     * La inicial del apartado: `down_payment × quantity` (RB-2).
     */
    public function totalDownPayment(): Money
    {
        return $this->downPayment->multiply($this->quantity);
    }

    /**
     * Lo que queda por financiar después de la inicial, del apartado entero.
     *
     * Cero cuando la inicial es el precio (SP-2), que es exactamente el caso
     * en que no hay plan.
     */
    public function financedAmount(): Money
    {
        return $this->financingPlan()?->financedAmount() ?? new Money(0, $this->unitPrice->currency);
    }

    /**
     * El plan de pago del **apartado entero**, o `null` si no queda nada que
     * financiar (SP-2).
     *
     * Con los rangos ya comprobados en el constructor, `tryFrom()` solo
     * devuelve `null` cuando la inicial es igual al precio.
     */
    public function financingPlan(): ?FinancingPlan
    {
        return FinancingPlan::tryFrom(
            $this->total(),
            $this->totalDownPayment(),
            $this->installmentsCount,
            $this->frequencyDays,
        );
    }
}
