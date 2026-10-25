<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Payment\ApproveReceiptAction;
use App\Actions\Payment\ApproveReceiptData;
use App\Actions\Payment\RejectReceiptAction;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\UnconfirmedStaleRateException;
use App\Domain\Layaway\DownPaymentExtension;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidRateException;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Payment\AppliedPayment;
use App\Domain\Payment\ApprovalPreview;
use App\Domain\Payment\PaymentAccountView;
use App\Domain\Payment\PaymentApplier;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Payment\ReceiptAlreadyProcessedException;
use App\Domain\Payment\RejectionReasonCode;
use App\Domain\Payment\UnconfirmedExcessException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\ApproveReceiptRequest;
use App\Http\Requests\Backoffice\RejectReceiptRequest;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Http\Resources\PaymentRowResource;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\Receipt;
use App\Models\User;
use App\Support\SignedFileUrl;
use DateTimeZone;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class ReceiptReviewController extends Controller
{
    use ProjectsMoney;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ExchangeRateService $rates,
        private readonly PaymentApplier $applier,
    ) {}

    /**
     * @return array{position: int, total: int, previousUrl: string|null, nextUrl: string|null}|null
     */
    private function queuePosition(Receipt $receipt): ?array
    {
        /** @var list<int> $ids */
        $ids = Receipt::query()
            ->pendingReview()
            ->whereHas('layaway', fn (Builder $layaway) => $layaway->where('status', '!=', LayawayStatus::Cancelled->value))
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $index = array_search((int) $receipt->getKey(), $ids, true);

        if ($index === false) {
            return null;
        }

        return [
            'position' => $index + 1,
            'total' => count($ids),
            'previousUrl' => isset($ids[$index - 1]) ? route('receipts.show', $ids[$index - 1]) : null,
            'nextUrl' => isset($ids[$index + 1]) ? route('receipts.show', $ids[$index + 1]) : null,
        ];
    }

    public function show(Request $request, Receipt $receipt): Response
    {
        Gate::forUser($request->user())->authorize('review', $receipt);

        $timezone = new DateTimeZone($this->context->organization()->timezone);
        /** @var Layaway $layaway */
        $layaway = Layaway::query()->findOrFail($receipt->layaway_id);
        $rate = $this->rates->current($this->context->organization());
        $reviewable = $receipt->status->awaitsReview() && $layaway->status->acceptsPayments();

        [$preview, $previewError] = $reviewable
            ? $this->previewOf($receipt, $layaway, (string) $receipt->declared_amount, $receipt->declared_currency, null)
            : [null, null];

        return Inertia::render('backoffice/receipts/Review', [
            'receipt' => $this->receiptOf($receipt, $timezone),
            'customer' => $this->customerOf($receipt),
            'layaway' => $this->layawayOf($layaway),
            'installments' => $this->installmentsOf($layaway),
            'payments' => $this->paymentsOf($layaway, $timezone, $request),
            'rate' => $rate instanceof RateSnapshot ? [
                'rate' => $rate->rate,
                'sourceLabel' => $rate->source->label(),
                'publishedAt' => $rate->publishedAt->setTimezone($timezone)->locale('es')->isoFormat('D [de] MMMM, h:mm a'),
            ] : null,
            'preview' => $preview?->toArray(),
            'previewError' => $previewError,
            'approval' => $reviewable ? [
                'url' => route('receipts.approve', $receipt),
                'previewUrl' => route('receipts.preview', $receipt),
                'currencies' => array_map(fn (Currency $currency): array => ['value' => $currency->value, 'label' => $currency->label()], Currency::cases()),
            ] : null,
            'rejection' => $receipt->status->awaitsReview() ? [
                'url' => route('receipts.reject', $receipt),
                'codes' => array_map(fn (RejectionReasonCode $code): array => ['value' => $code->value, 'label' => $code->label()], RejectionReasonCode::chosenByStaff()),
            ] : null,
            'inboxUrl' => route('receipts.index'),
            'queue' => $this->queuePosition($receipt),
        ]);
    }

    public function preview(Request $request, Receipt $receipt): JsonResponse
    {
        Gate::forUser($request->user())->authorize('review', $receipt);

        $amount = $request->query('amount');
        $rawCurrency = $request->query('currency');
        $currency = Currency::tryFrom(is_string($rawCurrency) ? $rawCurrency : '');
        $rate = $request->query('rate');
        $rate = is_string($rate) && $rate !== '' ? $rate : null;

        if (! is_string($amount) || preg_match(ApproveReceiptRequest::AMOUNT_PATTERN, $amount) !== 1) {
            return response()->json(['message' => 'Escribe el importe recibido con punto decimal y hasta dos decimales.'], 422);
        }

        if (! $currency instanceof Currency) {
            return response()->json(['message' => 'Elige la moneda del importe recibido.'], 422);
        }

        if ($rate !== null && preg_match(ApproveReceiptRequest::RATE_PATTERN, $rate) !== 1) {
            return response()->json(['message' => 'Escribe la tasa con punto decimal y hasta ocho decimales.'], 422);
        }

        if (! $receipt->status->awaitsReview()) {
            return response()->json(['message' => ReceiptAlreadyProcessedException::of($receipt)->getMessage()], 422);
        }

        /** @var Layaway $layaway */
        $layaway = Layaway::query()->findOrFail($receipt->layaway_id);

        [$preview, $error] = $this->previewOf($receipt, $layaway, $amount, $currency, $rate);

        return $preview instanceof ApprovalPreview
            ? response()->json(['preview' => $preview->toArray()])
            : response()->json(['message' => $error], 422);
    }

    public function reject(RejectReceiptRequest $request, Receipt $receipt, RejectReceiptAction $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $extension = $action->handle($receipt, $request->code(), $request->reason(), $actor);
        } catch (ReceiptAlreadyProcessedException $exception) {
            return back()->withErrors(['reason' => $exception->getMessage()]);
        }

        return redirect()->route('receipts.index')->with('status', 'Comprobante rechazado: el cliente verá tu explicación y podrá reportar de nuevo.'.$this->extensionNote($extension));
    }

    private function extensionNote(DownPaymentExtension $extension): string
    {
        return match ($extension) {
            DownPaymentExtension::Granted => ' Como el plazo de la inicial ya había vencido, el apartado tiene 24 horas más para pagarla.',
            DownPaymentExtension::AlreadyExtended => ' El plazo de la inicial ya se prorrogó una vez: si no llega, el apartado vencerá.',
            DownPaymentExtension::WindowStillOpen => ' La inicial todavía está dentro de su plazo.',
            DownPaymentExtension::NotAwaitingDownPayment => '',
        };
    }

    public function approve(ApproveReceiptRequest $request, Receipt $receipt, ApproveReceiptAction $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $applied = $action->handle($receipt, $request->toData(), $actor);
        } catch (UnconfirmedExcessException $exception) {
            return back()->withErrors(['confirm_excess' => $exception->getMessage()]);
        } catch (UnconfirmedStaleRateException $exception) {
            return back()->withErrors(['confirm_stale_rate' => $exception->getMessage()]);
        } catch (MissingExchangeRateException $exception) {
            return back()->withErrors(['rate' => $exception->getMessage()]);
        } catch (ReceiptAlreadyProcessedException|PaymentNotApplicableException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()]);
        }

        return redirect()->route('receipts.index')->with('status', $this->confirmationOf($applied));
    }

    /**
     * @return array{0: ApprovalPreview|null, 1: string|null}
     */
    private function previewOf(Receipt $receipt, Layaway $layaway, string $amount, Currency $currency, ?string $rateOverride): array
    {
        try {
            $data = new ApproveReceiptData($amount, $currency, $rateOverride, null);
            $rate = $data->manualRate() ?? $this->rates->current($this->context->organization());
        } catch (InvalidArgumentException|InvalidRateException $exception) {
            return [null, $exception->getMessage()];
        }

        try {
            return [$this->applier->preview($layaway, $data->settled(), $rate, $data->settledAmount, $data->settledCurrency, $receipt), null];
        } catch (PaymentNotApplicableException|MissingExchangeRateException $exception) {
            return [null, $exception->getMessage()];
        } catch (UniqueConstraintViolationException) {
            return [null, ReceiptAlreadyProcessedException::of($receipt->refresh())->getMessage()];
        } catch (DeadlockException $exception) {
            report($exception);

            return [null, 'El apartado está ocupado con otra operación. Vuelve a intentarlo en unos segundos.'];
        }
    }

    private function confirmationOf(AppliedPayment $applied): string
    {
        $numbers = Installment::query()
            ->whereKey(array_column($applied->plan->allocations, 'installmentId'))
            ->orderBy('number')
            ->pluck('number')
            ->all();

        $parts = [];

        if ($applied->downPaymentCents > 0) {
            $parts[] = sprintf('inicial de %s', MoneyFormatter::format(new Money($applied->downPaymentCents)));
        }

        if ($numbers !== []) {
            $parts[] = sprintf('%s a %s', MoneyFormatter::format(new Money($applied->plan->allocatedCents())), count($numbers) === 1
                ? sprintf('la cuota %d', $numbers[0])
                : sprintf('las cuotas %s y %d', implode(', ', array_slice($numbers, 0, -1)), $numbers[count($numbers) - 1]));
        }

        if ($applied->plan->unallocatedCents > 0) {
            $parts[] = sprintf('%s sin asignar', MoneyFormatter::format(new Money($applied->plan->unallocatedCents)));
        }

        $message = sprintf('Comprobante aprobado: acreditamos %s (%s).', MoneyFormatter::format($applied->payment->amount_cents_usd), implode('; ', $parts));

        return $message.($applied->statusAfter === LayawayStatus::Completed ? ' El apartado está pagado.' : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptOf(Receipt $receipt, DateTimeZone $timezone): array
    {
        $account = $receipt->payment_account_id === null ? null : PaymentAccount::query()->find($receipt->payment_account_id);

        return [
            'id' => (int) $receipt->getKey(),
            'status' => $receipt->status->value,
            'statusLabel' => $receipt->status->label(),
            'submittedAt' => $receipt->created_at?->setTimezone($timezone)->locale('es')->isoFormat('D [de] MMMM [de] YYYY, h:mm a'),
            'paidAt' => $receipt->paid_at->toDateString(),
            'method' => $receipt->payment_method->label(),
            'bank' => $receipt->bank,
            'reference' => $receipt->reference,
            'note' => $receipt->note,
            'declaredAmount' => (string) $receipt->declared_amount,
            'declaredCurrency' => $receipt->declared_currency->value,
            'declared' => MoneyFormatter::format(Money::fromDecimalString((string) $receipt->declared_amount, $receipt->declared_currency)),
            'paymentAccount' => $account instanceof PaymentAccount ? PaymentAccountView::of($account)->toArray() : null,
            'file' => [
                'url' => SignedFileUrl::forBackoffice($receipt),
                'kind' => $receipt->file_mime === 'application/pdf' ? 'pdf' : 'image',
            ],
            'reviewedBy' => $receipt->reviewer?->name,
            'rejectionReason' => $receipt->rejection_reason ?? $receipt->rejection_reason_code?->label(),
            'replacedBy' => ($retry = $receipt->retries()->latest('id')->first()) instanceof Receipt ? [
                'url' => route('receipts.show', $retry),
                'submittedAt' => $retry->created_at?->setTimezone($timezone)->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
            ] : null,
            'replaces' => $receipt->replaces instanceof Receipt ? [
                'url' => route('receipts.show', $receipt->replaces),
                'submittedAt' => $receipt->replaces->created_at?->setTimezone($timezone)->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
                'rejectionReason' => $receipt->replaces->rejection_reason ?? $receipt->replaces->rejection_reason_code?->label(),
            ] : null,
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function customerOf(Receipt $receipt): ?array
    {
        $customer = Customer::query()->find($receipt->customer_id);

        return $customer instanceof Customer ? [
            'fullName' => $customer->full_name,
            'documentNumber' => $customer->document_number,
            'phone' => $customer->phone,
        ] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function layawayOf(Layaway $layaway): array
    {
        return [
            'code' => $layaway->code,
            'url' => route('layaways.show', $layaway),
            'status' => $layaway->status->value,
            'statusLabel' => $layaway->status->label(),
            'productName' => $layaway->product_name_snapshot,
            'total' => $this->money($layaway->total_cents),
            'downPayment' => $this->money($layaway->terms()->totalDownPayment()),
            'paid' => $this->money($layaway->paid_cents),
            'remaining' => $this->money($layaway->remaining_cents),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function installmentsOf(Layaway $layaway): array
    {
        return array_map(fn (Installment $installment): array => [
            'number' => $installment->number,
            'dueDateLabel' => $installment->due_date->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
            'amount' => $this->money($installment->amount_cents),
            'paid' => $this->money($installment->paid_cents),
            'status' => $installment->status->value,
            'statusLabel' => $installment->status->label(),
        ], $layaway->installments()->get()->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paymentsOf(Layaway $layaway, DateTimeZone $timezone, Request $request): array
    {
        return Payment::query()
            ->where('layaway_id', $layaway->getKey())
            ->with(['allocations.installment', 'receipt'])
            ->latest('settled_at')
            ->latest('id')
            ->get()
            ->map(fn (Payment $payment): array => (new PaymentRowResource($payment, $timezone))->resolve($request))
            ->values()
            ->all();
    }
}
