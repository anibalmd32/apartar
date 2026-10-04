<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Domain\Exchange\ManualRate;
use App\Domain\Layaway\StaffReason;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidRateException;
use App\Domain\Money\Money;
use App\Domain\Payment\PaymentAmounts;
use App\Domain\Payment\PaymentMethod;
use App\Models\PaymentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Un pago que el negocio registra a mano, sin comprobante (`REQ-PAY-007`).
 *
 * **La nota es obligatoria, de 10 a 500 caracteres** (RB-1): es la única
 * evidencia de un pago sin adjunto, y se valida con `StaffReason`, la misma
 * regla que el motivo de cancelar o reprogramar. El importe es positivo
 * (SP-2), la tasa editada se puede usar y guardar (`ManualRate`), y la clave
 * de idempotencia es un UUID (SP-5): la genera el formulario al montarse, como
 * la del apartado (`REQ-LAY-001` RB-12), y lo que impide el doble clic es
 * `UNIQUE(organization_id, idempotency_key)` en `payments`.
 *
 * Que la cuenta de cobro sea del negocio del apartado lo comprueba la acción,
 * que es quien recibe el apartado (`T-062`); que `paidAt` no sea futura
 * (SP-6), el formulario, con el reloj de la petición.
 */
final readonly class ManualPaymentData
{
    public string $note;

    /**
     * @throws InvalidArgumentException si el importe, la nota o la clave no tienen forma válida
     * @throws InvalidRateException si la tasa no es un decimal positivo de hasta ocho cifras decimales
     */
    public function __construct(
        public PaymentMethod $method,
        public ?PaymentAccount $paymentAccount,
        public string $amount,
        public Currency $currency,
        public ?string $rateOverride,
        public CarbonImmutable $paidAt,
        string $note,
        public string $idempotencyKey,
    ) {
        PaymentAmounts::assertPositive($amount, $currency, 'del pago');

        if ($rateOverride !== null) {
            new ManualRate($rateOverride);
        }

        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException(
                'La clave de idempotencia es el UUID que el formulario genera al montarse (REQ-PAY-007 SP-5).'
            );
        }

        $this->note = (new StaffReason($note))->text;
    }

    /**
     * El importe, como `Money` de su moneda.
     */
    public function money(): Money
    {
        return Money::fromDecimalString($this->amount, $this->currency);
    }
}
