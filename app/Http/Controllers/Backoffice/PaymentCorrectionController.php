<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Payment\CorrectPaymentAction;
use App\Actions\Payment\VoidPaymentAction;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\RateSourceResolver;
use App\Domain\Exchange\UnconfirmedRateDeviationException;
use App\Domain\Exchange\UnconfirmedStaleRateException;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\IdempotencyKeyReusedException;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Payment\PaymentNotVoidableException;
use App\Domain\Payment\ReceiptAlreadyProcessedException;
use App\Domain\Payment\UnconfirmedExcessException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\CorrectPaymentRequest;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\User;
use App\Queries\PaymentFieldOptionsQuery;
use App\Support\LayawayCode;
use App\Support\ManualPaymentDates;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class PaymentCorrectionController extends Controller
{
    public function store(CorrectPaymentRequest $request, Payment $payment, CorrectPaymentAction $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $target = $request->target($payment);

        try {
            $corrected = $action->handle($payment, $target, $request->toData(), $actor);
        } catch (UnconfirmedExcessException $exception) {
            return back()->withErrors(['confirm_excess' => $exception->getMessage()]);
        } catch (UnconfirmedStaleRateException $exception) {
            return back()->withErrors(['confirm_stale_rate' => $exception->getMessage()]);
        } catch (UnconfirmedRateDeviationException $exception) {
            return back()->withErrors(['confirm_rate_deviation' => $exception->getMessage()]);
        } catch (MissingExchangeRateException $exception) {
            return back()->withErrors(['rate' => $exception->getMessage()]);
        } catch (PaymentNotApplicableException|IdempotencyKeyReusedException|ReceiptAlreadyProcessedException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()]);
        } catch (PaymentNotVoidableException $exception) {
            return back()->withErrors(['payment' => $exception->getMessage()]);
        } catch (DuplicateReceiptException $exception) {
            return back()->withErrors([$exception->field() => $exception->getMessage()]);
        }

        $moved = (int) $target->getKey() !== (int) $payment->layaway_id;

        return to_route('layaways.show', $target)->with('status', $moved
            ? sprintf('Pago de %s movido a %s.', MoneyFormatter::format($corrected->amount_cents_usd), $target->code)
            : sprintf('Pago corregido: ahora es de %s.', MoneyFormatter::format($corrected->amount_cents_usd)));
    }

    public function preview(Request $request, Payment $payment, VoidPaymentAction $void, ManualPaymentController $manual): JsonResponse
    {
        $layaway = Layaway::query()->findOrFail($payment->layaway_id);
        Gate::forUser($request->user())->authorize('manage', $layaway);

        /** @var User $actor */
        $actor = $request->user();

        DB::beginTransaction();

        try {
            $void->handle($payment, $actor, 'Vista previa de la corrección del pago.');

            return $manual->preview($request, $layaway->refresh());
        } catch (PaymentNotVoidableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } finally {
            DB::rollBack();
        }
    }

    public function target(Request $request, Payment $payment, TenantContext $context, PaymentFieldOptionsQuery $fields): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', Layaway::query()->findOrFail($payment->layaway_id));

        $code = LayawayCode::normalize($request->string('codigo')->toString());
        $layaway = Layaway::query()->where('code', $code)->first();

        if (! $layaway instanceof Layaway || (int) $layaway->getKey() === (int) $payment->layaway_id) {
            return response()->json(['message' => 'No hay otro apartado con ese código en tu negocio.'], 422);
        }

        if (! $layaway->status->acceptsPayments()) {
            return response()->json(['message' => sprintf('El apartado %s está %s y no recibe pagos.', $layaway->code, mb_strtolower($layaway->status->label()))], 422);
        }

        $customer = Customer::query()->find($layaway->customer_id);
        [$min, $max] = ManualPaymentDates::bounds(new DateTimeZone($context->organization()->timezone));

        return response()->json([
            'layaway' => [
                'code' => $layaway->code,
                'customer' => $customer instanceof Customer ? $customer->full_name : null,
                'product' => $layaway->product_name_snapshot,
                'remaining' => MoneyFormatter::format($layaway->remaining_cents),
            ],
            'options' => [
                'storeUrl' => route('payments.correct', ['payment' => $payment, 'destino' => $layaway->code]),
                'previewUrl' => route('layaways.payments.preview', $layaway),
                ...$fields->for($min, $max, app(RateSourceResolver::class)->forLayaway($layaway)),
            ],
        ]);
    }
}
