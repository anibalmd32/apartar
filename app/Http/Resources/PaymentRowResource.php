<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un pago del historial (`REQ-LAY-022` RB-1, RB-2, RB-7): lo que ve el
 * cliente en su portal y el negocio en la revisión de un comprobante.
 *
 * Cada pago va con **el par que se congeló al conciliarlo** (`REQ-FX-006`):
 * los dólares que entraron en el balance, lo declarado en su moneda y la tasa
 * con su fuente. **Nunca se reconvierte con la tasa de hoy** (RB-2): por eso
 * `amountUsd` va sin su proyección a bolívares —la proyección sería la de
 * hoy, y diría otra cifra que la que el cliente pagó—, y la tasa es la del
 * pago aunque el negocio haya cambiado de fuente después (SP-7).
 *
 * Lo que pagó la inicial va aparte de las cuotas —un primer pago con
 * `appliedTo` vacío no diría qué pagó—, y el sobrante, si lo hubo, también.
 * Un pago registrado a mano va marcado y con su nota (RB-7). Nunca lleva quién
 * del negocio lo registró ni identificadores internos (RB-6).
 *
 * Espera el pago con `allocations.installment` y `receipt` cargados: la
 * lista de un apartado se pide entera, y una consulta por fila sería `N+1`.
 */
final class PaymentRowResource extends JsonResource
{
    public function __construct(Payment $payment, private readonly DateTimeZone $timezone)
    {
        parent::__construct($payment);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Payment $payment */
        $payment = $this->resource;
        $inDollars = $payment->declared_currency === Currency::USD;
        $manual = $payment->receipt_id === null;
        $allocated = (int) $payment->allocations->sum(fn (PaymentAllocation $allocation): int => $allocation->amount_cents->cents);
        // La parte que pagó la inicial no es una cuota ni sobrante: es lo que
        // queda (`REQ-PAY-008` RB-3, amount = inicial + asignaciones + sobrante).
        $downPayment = $payment->amount_cents_usd->cents - $allocated - $payment->unallocated_cents->cents;

        return [
            'settledAt' => $payment->settled_at->setTimezone($this->timezone)->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
            'amountUsd' => [
                'cents' => $payment->amount_cents_usd->cents,
                'usd' => MoneyFormatter::format($payment->amount_cents_usd),
                'ves' => null,
            ],
            'declaredAmount' => MoneyFormatter::format(Money::fromDecimalString($payment->declared_amount, $payment->declared_currency)),
            // Un pago en dólares no convirtió nada: su tasa `1` no dice nada.
            'rateApplied' => $inDollars ? null : $payment->rate_applied,
            'rateSource' => $payment->rate_source->value,
            'rateSourceLabel' => $payment->rate_source->label(),
            'method' => $payment->receipt?->payment_method->label() ?? 'Registrado por el negocio',
            'appliedTo' => $payment->allocations
                ->map(fn (PaymentAllocation $allocation): ?int => $allocation->installment?->number)
                ->filter()
                ->sort()
                ->values()
                ->all(),
            'downPayment' => $downPayment > 0 ? MoneyFormatter::format(new Money($downPayment)) : null,
            'unallocated' => $payment->unallocated_cents->cents > 0 ? MoneyFormatter::format($payment->unallocated_cents) : null,
            'registeredByBusiness' => $manual,
            'note' => $manual ? $payment->note : null,
        ];
    }
}
