<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Exchange\RateSource;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use InvalidArgumentException;

/**
 * Lo que haría aprobar un comprobante, sin hacerlo (`REQ-PAY-004` RB-6,
 * `REQ-PAY-005` SP-5).
 *
 * La pantalla de revisión dice «se aplicará a la cuota 3» **antes** de
 * aprobar, y el excedente se confirma viéndolo. Si esa cuenta la hiciera otro
 * código que el que aprueba, el aviso y lo que pasa al pulsar podrían no
 * coincidir —el redondeo de la tasa, la inicial, una cuota en mora—, y el
 * negocio confiaría en una cifra que no es la que se escribe. Por eso sale de
 * `PaymentApplier::preview()`, que ejecuta **la misma aplicación** dentro de
 * una transacción que siempre se deshace, y esto es lo que queda de ella.
 *
 * Las cuotas van por **número**, no por `id`: un apartado que espera la
 * inicial no tiene cuotas hasta que se activa, y las que creó la vista previa
 * ya no existen.
 */
final readonly class ApprovalPreview
{
    /**
     * @param  list<array{number: int, cents: int, covers: bool}>  $allocations  qué se lleva cada cuota, y si queda cubierta
     */
    public function __construct(
        public Money $amount,
        public string $rate,
        public RateSource $rateSource,
        public int $downPaymentCents,
        public array $allocations,
        public int $unallocatedCents,
        public LayawayStatus $statusBefore,
        public LayawayStatus $statusAfter,
    ) {
        $allocated = array_sum(array_column($allocations, 'cents'));

        if ($downPaymentCents + $allocated + $unallocatedCents !== $amount->cents) {
            throw new InvalidArgumentException(sprintf(
                'La vista previa de un pago de %d centavos suma %d de inicial, %d asignados y %d sin asignar.',
                $amount->cents,
                $downPaymentCents,
                $allocated,
                $unallocatedCents,
            ));
        }
    }

    /** Si aprobar activaría el apartado: pagaría la inicial (`REQ-PAY-005` RB-5). */
    public function activates(): bool
    {
        return $this->downPaymentCents > 0;
    }

    /** Si quedaría saldado (RB-7). */
    public function completes(): bool
    {
        return $this->statusAfter === LayawayStatus::Completed && $this->statusBefore !== LayawayStatus::Completed;
    }

    /** Si sobra algo que exige confirmación (SP-5). */
    public function hasExcess(): bool
    {
        return $this->unallocatedCents > 0;
    }

    /**
     * Lo que pinta la pantalla de revisión: cifras ya formateadas y la frase
     * que la resume.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'amount' => MoneyFormatter::format($this->amount),
            'rate' => $this->rate,
            'rateSource' => $this->rateSource->value,
            'rateSourceLabel' => $this->rateSource->label(),
            'downPayment' => $this->activates() ? MoneyFormatter::format(new Money($this->downPaymentCents)) : null,
            'allocations' => array_map(fn (array $allocation): array => [
                'number' => $allocation['number'],
                'amount' => MoneyFormatter::format(new Money($allocation['cents'])),
                'covers' => $allocation['covers'],
            ], $this->allocations),
            'unallocated' => $this->hasExcess() ? MoneyFormatter::format(new Money($this->unallocatedCents)) : null,
            // La cifra exacta que se confirma (SP-5): la que el servidor
            // compara al aprobar con la que de verdad sobra.
            'unallocatedCents' => $this->unallocatedCents,
            'statusAfter' => $this->statusAfter->value,
            'statusAfterLabel' => $this->statusAfter->label(),
            'summary' => $this->summary(),
        ];
    }

    /**
     * «Se aplicará a la cuota 3», como en el *happy path* de `REQ-PAY-004`.
     */
    public function summary(): string
    {
        $parts = [];

        if ($this->activates()) {
            $parts[] = sprintf('activará el apartado con la inicial de %s', MoneyFormatter::format(new Money($this->downPaymentCents)));
        }

        $numbers = array_column($this->allocations, 'number');

        if ($numbers !== []) {
            $parts[] = count($numbers) === 1
                ? sprintf('se aplicará a la cuota %d', $numbers[0])
                : sprintf('se aplicará a las cuotas %s', self::listOf($numbers));
        }

        if ($this->hasExcess()) {
            $parts[] = sprintf('sobrarán %s sin asignar', MoneyFormatter::format(new Money($this->unallocatedCents)));
        }

        if ($this->completes()) {
            $parts[] = 'el apartado quedará pagado';
        }

        return ucfirst(implode('; ', $parts)).'.';
    }

    /**
     * @param  list<int>  $numbers
     */
    private static function listOf(array $numbers): string
    {
        $last = array_pop($numbers);

        return implode(', ', $numbers).' y '.$last;
    }
}
