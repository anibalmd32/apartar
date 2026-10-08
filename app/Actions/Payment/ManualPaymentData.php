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
use App\Domain\Payment\PaymentOrigin;
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
        /**
         * Los centavos de sobrante que el negocio vio y confirmó (SP-7, como
         * `REQ-PAY-005` SP-5). Añadido en `T-062`, igual que en
         * `ApproveReceiptData`: lo confirmado es una cifra.
         */
        public ?int $confirmedExcessCents = null,
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

        if ($confirmedExcessCents !== null && $confirmedExcessCents < 1) {
            throw new InvalidArgumentException('Un sobrante confirmado es de al menos un centavo.');
        }

        $this->note = (new StaffReason($note))->text;
    }

    /**
     * La tasa escrita a mano, **solo si hay algo que convertir**: con dólares
     * se ignora y el pago guarda la fuente vigente (`REQ-FX-006` RB-4), como
     * en la aprobación.
     */
    public function manualRate(): ?ManualRate
    {
        return $this->rateOverride !== null && $this->currency !== Currency::USD ? new ManualRate($this->rateOverride) : null;
    }

    /**
     * Cómo se pagó, para guardarlo con el pago (`T-062`): es lo único que
     * queda de un pago sin comprobante además de la nota (RB-6).
     */
    public function origin(): PaymentOrigin
    {
        return new PaymentOrigin($this->method, $this->paymentAccount?->getKey() === null ? null : (int) $this->paymentAccount->getKey(), $this->paidAt->startOfDay());
    }

    /**
     * El importe, como `Money` de su moneda.
     */
    public function money(): Money
    {
        return Money::fromDecimalString($this->amount, $this->currency);
    }
}
