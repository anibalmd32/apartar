<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Payment\VoidPaymentAction;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Payment\PaymentNotVoidableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\VoidPaymentRequest;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class PaymentVoidController extends Controller
{
    public function __invoke(VoidPaymentRequest $request, Payment $payment, VoidPaymentAction $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $voided = $action->handle($payment, $actor, $request->reason());
        } catch (PaymentNotVoidableException $exception) {
            return back()->withErrors(['payment' => $exception->getMessage()]);
        }

        $layaway = Layaway::query()->findOrFail($voided->layaway_id);
        $receipt = $voided->receipt_id !== null ? ' El comprobante volvió a revisión.' : '';

        return to_route('layaways.show', $layaway)
            ->with('status', sprintf('Pago de %s anulado.%s', MoneyFormatter::format($voided->amount_cents_usd), $receipt));
    }
}
