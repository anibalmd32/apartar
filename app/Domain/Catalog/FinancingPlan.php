<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Money\Money;
use InvalidArgumentException;

/**
 * El plan de pago de un producto: qué se paga de entrada y en cuántas cuotas
 * queda el resto (`REQ-BOF-020` RB-7).
 *
 * ## Existe para que la previsualización no sea una segunda cuenta
 *
 * RB-7 pide que el formulario muestre en vivo «inicial $50 + 5 cuotas de $30
 * cada 15 días» **usando el mismo cálculo del dominio que generará el
 * cronograma real**. Lo tentador es escribir esa división en el `.tsx`, y es
 * justo lo que no puede ser: si el cliente reparte 150 entre 5 con coma
 * flotante y el servidor reparte con `Money::allocateEvenly()`, el negocio ve
 * una cuota en el formulario y su cliente ve otra en el catálogo. Por un
 * céntimo — que es suficiente para que nadie vuelva a fiarse de la cifra.
 *
 * Así que el reparto se hace aquí, con `allocateEvenly()`, que ya está probado
 * con quinientos casos aleatorios (`T-018`) y cuya invariante es exacta por
 * construcción: **la suma de las cuotas más la inicial es el precio**, para
 * cualquier importe y cualquier número de cuotas.
 *
 * ## Y no es `ScheduleGenerator`
 *
 * El cronograma real —con sus fechas y sus vencimientos— lo genera
 * `ScheduleGenerator` (`T-041`), y **sus importes salen de esta clase**:
 * `LayawayTerms::financingPlan()` construye un `FinancingPlan` del apartado
 * entero y el generador toma sus `installments()`. Hay un solo reparto en el
 * sistema, y `ScheduleGeneratorTest` afirma que con una unidad la cuota de la
 * ficha y la del cronograma coinciden al céntimo. Lo que esta clase **no**
 * hace es multiplicar: con varias unidades, el plan es el del total, no el de
 * una unidad por la cantidad (ver `LayawayTerms`).
 *
 * Las fechas no se calculan aquí a propósito. `frequency_days` viaja porque la
 * frase de RB-7 la nombra —«cada 15 días»— pero convertirla en vencimientos
 * exige la zona horaria de la organización y las reglas de `REQ-LAY-002`, que
 * son de H3.
 *
 * ## Solo se construye con términos coherentes
 *
 * Las seis reglas de RB-1 las comprueba `CoherentFinancingTerms` **antes**, y
 * esta clase asume que ya pasaron: un plan con la inicial por encima del
 * precio no es un plan que haya que pintar con cuidado, es uno que no debería
 * haberse construido. Las dos guardas de abajo protegen la aritmética, no la
 * regla de negocio.
 */
final readonly class FinancingPlan
{
    /**
     * @param  Money  $price  precio total del producto
     * @param  Money  $downPayment  lo que se paga de entrada; estrictamente menor que el precio
     * @param  int  $installmentsCount  número de cuotas, al menos 1
     * @param  int  $frequencyDays  días entre cuotas
     *
     * @throws InvalidArgumentException si los términos no permiten repartir nada
     */
    public function __construct(
        public Money $price,
        public Money $downPayment,
        public int $installmentsCount,
        public int $frequencyDays,
    ) {
        if ($installmentsCount < 1) {
            throw new InvalidArgumentException(sprintf(
                'Un plan de pago necesita al menos una cuota, y se pidieron %d. Las seis reglas de '
                .'`REQ-BOF-020` RB-1 lo comprueban antes: si esto lanza, alguien construyó el plan sin pasar '
                .'por CoherentFinancingTerms.',
                $installmentsCount,
            ));
        }

        if ($downPayment->cents >= $price->cents) {
            throw new InvalidArgumentException(
                'La cuota inicial tiene que ser estrictamente menor que el precio: con la inicial igual al '
                .'precio no hay nada que financiar, y por encima el plan devolvería cuotas negativas. Lo '
                .'comprueba CoherentFinancingTerms antes de llegar aquí.'
            );
        }
    }

    /**
     * El mismo plan, o `null` si los términos no permiten repartir nada.
     *
     * Es la variante que usa la previsualización (RB-7), porque un formulario
     * a medio escribir pasa por estados imposibles todo el rato —cero cuotas
     * mientras se borra el campo, la inicial por encima del precio mientras se
     * teclea— y ninguno de ellos es un error que merezca un mensaje: lo único
     * que toca es no pintar un plan.
     *
     * Delega en el constructor en lugar de repetir sus dos condiciones, que es
     * lo que impide que la previsualización y la aritmética acaben opinando
     * cosas distintas sobre qué es un plan válido. Las seis reglas de RB-1 son
     * otra cosa y las comprueba `CoherentFinancingTerms` al guardar.
     */
    public static function tryFrom(
        Money $price,
        Money $downPayment,
        int $installmentsCount,
        int $frequencyDays,
    ): ?self {
        try {
            return new self($price, $downPayment, $installmentsCount, $frequencyDays);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Lo que queda por financiar: el precio menos la inicial.
     */
    public function financedAmount(): Money
    {
        return $this->price->subtract($this->downPayment);
    }

    /**
     * Las cuotas, una a una, con el residuo en la última (`REQ-FX-005`).
     *
     * `$100` en tres cuotas son `[33.33, 33.33, 33.34]` y no tres de `33.33`,
     * porque el céntimo que falta es dinero de alguien.
     *
     * @return list<Money>
     */
    public function installments(): array
    {
        return $this->financedAmount()->allocateEvenly($this->installmentsCount);
    }

    /**
     * La cuota que se repite, que es la que dice la frase de RB-7.
     */
    public function installmentAmount(): Money
    {
        return $this->installments()[0];
    }

    /**
     * La última, que puede llevar el residuo.
     */
    public function lastInstallmentAmount(): Money
    {
        $installments = $this->installments();

        return $installments[count($installments) - 1];
    }

    /**
     * Si la última cuota difiere de las demás.
     *
     * La UI lo dice con esas palabras —«última cuota: $30.02»— en lugar de
     * enseñar una media que no se cobra nunca (`REQ-CAT-003` RB-2). Con una
     * sola cuota siempre es `false`: no hay «las demás» con las que diferir.
     */
    public function hasUnevenLastInstallment(): bool
    {
        return $this->installmentsCount > 1
            && $this->lastInstallmentAmount()->cents !== $this->installmentAmount()->cents;
    }
}
