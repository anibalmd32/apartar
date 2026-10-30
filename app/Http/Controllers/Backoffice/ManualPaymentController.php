<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Payment\RegisterManualPaymentAction;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\ManualRate;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\UnconfirmedRateDeviationException;
use App\Domain\Exchange\UnconfirmedStaleRateException;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidRateException;
use App\Domain\Money\Money;
use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\IdempotencyKeyReusedException;
use App\Domain\Payment\PaymentAmounts;
use App\Domain\Payment\PaymentApplier;
use App\Domain\Payment\PaymentConfirmation;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Payment\PaymentOrigin;
use App\Domain\Payment\UnconfirmedExcessException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\ApproveReceiptRequest;
use App\Http\Requests\Backoffice\StoreManualPaymentRequest;
use App\Models\Layaway;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DeadlockException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class ManualPaymentController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ExchangeRateService $rates,
        private readonly PaymentApplier $applier,
    ) {}

    public function store(StoreManualPaymentRequest $request, Layaway $layaway, RegisterManualPaymentAction $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $payment = $action->handle($layaway, $request->toData(), $actor);
        } catch (UnconfirmedExcessException $exception) {
            return back()->withErrors(['confirm_excess' => $exception->getMessage()]);
        } catch (UnconfirmedStaleRateException $exception) {
            return back()->withErrors(['confirm_stale_rate' => $exception->getMessage()]);
        } catch (UnconfirmedRateDeviationException $exception) {
            return back()->withErrors(['confirm_rate_deviation' => $exception->getMessage()]);
        } catch (MissingExchangeRateException $exception) {
            return back()->withErrors(['rate' => $exception->getMessage()]);
        } catch (PaymentNotApplicableException|IdempotencyKeyReusedException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()]);
        } catch (DuplicateReceiptException $exception) {
            return back()->withErrors([$exception->field() => $exception->getMessage()]);
        }

        return redirect()->route('layaways.show', $layaway)->with('status', PaymentConfirmation::of($payment));
    }

    public function preview(Request $request, Layaway $layaway): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', $layaway);

        $amount = $request->query('amount');
        $rawCurrency = $request->query('currency');
        $currency = Currency::tryFrom(is_string($rawCurrency) ? $rawCurrency : '');
        $rate = $request->query('rate');
        $rate = is_string($rate) && $rate !== '' && $currency !== Currency::USD ? $rate : null;

        if (! is_string($amount) || preg_match(ApproveReceiptRequest::AMOUNT_PATTERN, $amount) !== 1) {
            return response()->json(['message' => 'Escribe el importe con punto decimal y hasta dos decimales.'], 422);
        }

        if (! $currency instanceof Currency) {
            return response()->json(['message' => 'Elige la moneda del pago.'], 422);
        }

        if ($rate !== null && preg_match(ApproveReceiptRequest::RATE_PATTERN, $rate) !== 1) {
            return response()->json(['message' => 'Escribe la tasa con punto decimal y hasta ocho decimales.'], 422);
        }

        /** @var User $actor */
        $actor = $request->user();

        try {
            PaymentAmounts::assertPositive($amount, $currency, 'del pago');
            $settled = Money::fromDecimalString($amount, $currency);
            $snapshot = $rate !== null ? new ManualRate($rate) : $this->rates->current($this->context->organization());
        } catch (InvalidArgumentException|InvalidRateException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        try {
            $preview = $this->applier->preview(
                $layaway,
                $settled,
                $snapshot,
                $amount,
                $currency,
                null,
                $actor,
                new PaymentOrigin(PaymentMethod::EfectivoUsd, null, CarbonImmutable::now()),
            );
        } catch (PaymentNotApplicableException|MissingExchangeRateException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (DeadlockException $exception) {
            report($exception);

            return response()->json(['message' => 'El apartado está ocupado con otra operación. Vuelve a intentarlo en unos segundos.'], 422);
        }

        return response()->json(['preview' => $preview->toArray()]);
    }
}
