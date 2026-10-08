<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\Receipt;
use Carbon\CarbonImmutable;

/**
 * Cómo se pagó un pago: el método, la cuenta de cobro a la que dice haber
 * pagado el cliente y el día (`T-062`).
 *
 * Viaja hasta `PaymentApplier::apply()`, que es el único que escribe pagos y
 * los escribe enteros en el `INSERT`. Al aprobar un comprobante sale del
 * comprobante; al registrar un pago a mano, de lo que escribió el negocio. Es
 * lo que hace iguales los dos caminos (`REQ-PAY-007` RB-2) y lo único que
 * queda de un pago sin comprobante además de su nota.
 */
final readonly class PaymentOrigin
{
    public function __construct(
        public PaymentMethod $method,
        public ?int $paymentAccountId,
        /** Un día del calendario de quien pagó, sin hora: se guarda tal cual. */
        public CarbonImmutable $paidOn,
    ) {}

    public static function ofReceipt(Receipt $receipt): self
    {
        return new self(
            $receipt->payment_method,
            $receipt->payment_account_id,
            CarbonImmutable::parse($receipt->paid_at->toDateString()),
        );
    }

    /**
     * @return array{payment_method: string, payment_account_id: int|null, paid_on: string}
     */
    public function toColumns(): array
    {
        return [
            'payment_method' => $this->method->value,
            'payment_account_id' => $this->paymentAccountId,
            'paid_on' => $this->paidOn->toDateString(),
        ];
    }
}
