<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Payment\ReallocatePaymentAction;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Payment\AllocationPlan;
use App\Domain\Payment\InvalidReallocationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\ReallocatePaymentRequest;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Reasignar un pago entre las cuotas de su apartado (`REQ-PAY-009`), desde la
 * vista del apartado.
 *
 * El `{payment}` es numérico y llega filtrado por el tenant: el de otra
 * organización es `404` (SP-2). Lo que la acción rechaza vuelve al formulario
 * en `allocations`, con el motivo y la cifra.
 */
final class PaymentReallocationController extends Controller
{
    public function __invoke(ReallocatePaymentRequest $request, Payment $payment, ReallocatePaymentAction $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $plan = $action->handle($payment, $request->allocations(), $actor, $request->string('reason')->toString());
        } catch (InvalidReallocationException $exception) {
            return back()->withErrors(['allocations' => $exception->getMessage()]);
        }

        $layaway = Layaway::query()->findOrFail($payment->layaway_id);

        return to_route('layaways.show', $layaway)->with('status', 'Pago reasignado: '.$this->describe($plan).'.');
    }

    /**
     * «$30.00 a la cuota 3 y $30.00 a la cuota 5».
     */
    private function describe(AllocationPlan $plan): string
    {
        $numbers = Installment::query()->whereKey(array_column($plan->allocations, 'installmentId'))->pluck('number', 'id')->all();
        $parts = [];

        foreach ($plan->allocations as $allocation) {
            $parts[(int) ($numbers[$allocation['installmentId']] ?? 0)] = sprintf(
                '%s a la cuota %d',
                MoneyFormatter::format(new Money($allocation['cents'])),
                $numbers[$allocation['installmentId']] ?? 0,
            );
        }

        ksort($parts);
        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts).' y '.$last;
    }
}
