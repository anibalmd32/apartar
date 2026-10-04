<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Domain\Exchange\ManualRate;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidRateException;
use App\Domain\Money\Money;
use App\Domain\Payment\PaymentAmounts;
use InvalidArgumentException;

/**
 * Lo que el negocio confirma al aprobar un comprobante (`REQ-PAY-005`).
 *
 * El negocio puede ajustar **lo que de verdad recibió** (RB-3): se concilia
 * esto y el comprobante conserva lo que el cliente declaró. Y puede aplicar
 * una tasa distinta de la vigente si el pago llegó días antes (`REQ-FX-006`
 * SP-3): entonces `rateOverride` no es nulo y el pago queda con
 * `rate_source = manual`. Sin ella, la tasa es la vigente **de esa
 * organización** (RB-10), que resuelve la acción.
 *
 * No se puede construir con un importe de cero (SP-4) ni con una tasa que no
 * se pueda usar o guardar tal cual (`ManualRate`).
 */
final readonly class ApproveReceiptData
{
    public const NOTE_MAX = 500;

    /**
     * @param  string|null  $rateOverride  tasa a aplicar; por defecto la vigente de la organización
     *
     * @throws InvalidArgumentException si el importe no es positivo o la nota es demasiado larga
     * @throws InvalidRateException si la tasa no es un decimal positivo de hasta ocho cifras decimales
     */
    public function __construct(
        public string $settledAmount,
        public Currency $settledCurrency,
        public ?string $rateOverride,
        public ?string $note,
    ) {
        PaymentAmounts::assertPositive($settledAmount, $settledCurrency, 'conciliado');

        if ($rateOverride !== null) {
            new ManualRate($rateOverride);
        }

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            throw new InvalidArgumentException(sprintf('La nota tiene como mucho %d caracteres.', self::NOTE_MAX));
        }
    }

    /**
     * Lo conciliado, como `Money` de su moneda.
     */
    public function settled(): Money
    {
        return Money::fromDecimalString($this->settledAmount, $this->settledCurrency);
    }
}
