<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Layaway\CancelLayawayAction;
use App\Actions\Layaway\MarkDeliveredAction;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Layaway\IllegalLayawayTransitionException;
use App\Domain\Layaway\LayawayNotDeliverableException;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\CancelLayawayRequest;
use App\Http\Requests\Portal\StoreReceiptRequest;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Http\Resources\PaymentRowResource;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Queries\LayawayActivityQuery;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class LayawayController extends Controller
{
    use ProjectsMoney;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ExchangeRateService $rates,
        private readonly LayawayActivityQuery $activity,
    ) {}

    public function show(Request $request, Layaway $layaway): Response
    {
        Gate::forUser($request->user())->authorize('manage', $layaway);

        $timezone = new DateTimeZone($this->context->organization()->timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $installments = $layaway->installments()->get()->all();
        $schedule = Installment::scheduleOf($installments, $timezone);
        $collecting = in_array($layaway->status, [LayawayStatus::Active, LayawayStatus::Overdue], true);
        $customer = Customer::query()->find($layaway->customer_id);
        $payments = Payment::query()
            ->where('layaway_id', $layaway->getKey())
            ->with(['allocations.installment', 'receipt'])
            ->latest('settled_at')
            ->latest('id')
            ->get();

        return Inertia::render('backoffice/layaways/Show', [
            'layaway' => [
                'code' => $layaway->code,
                'status' => $layaway->status->value,
                'statusLabel' => $layaway->status->label(),
                'productName' => $layaway->product_name_snapshot,
                'quantity' => $layaway->quantity,
                'total' => $this->money($layaway->total_cents),
                'downPayment' => $this->money($layaway->terms()->totalDownPayment()),
                'paid' => $this->money($layaway->paid_cents),
                'remaining' => $this->money($layaway->remaining_cents),
                'createdAt' => $this->moment($layaway->created_at, $timezone),
                'cancelledAt' => $this->moment($layaway->cancelled_at, $timezone),
                'deliveredAt' => $this->moment($layaway->delivered_at, $timezone),
                'cancellationReason' => $layaway->cancellation_reason,
                'cancelUrl' => $layaway->status->canTransitionTo(LayawayStatus::Cancelled) ? route('layaways.cancel', $layaway) : null,
                'deliverUrl' => $layaway->status === LayawayStatus::Completed && $layaway->delivered_at === null ? route('layaways.deliver', $layaway) : null,
            ],
            'customer' => $customer instanceof Customer ? [
                'fullName' => $customer->full_name,
                'documentNumber' => $customer->document_number,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'url' => route('customers.show', $customer),
                'chatUrl' => ($conversation = $customer->conversation) instanceof Conversation ? route('chat.show', $conversation) : null,
            ] : null,
            'activity' => $this->activity->for($layaway),
            'installments' => array_map(function (Installment $installment) use ($collecting, $schedule, $today): array {
                $covered = $installment->paid_cents->cents >= $installment->amount_cents->cents;
                $window = $collecting && ! $covered ? $schedule->validMoveWindow($installment->number, $today) : null;
                $movable = $window !== null && ! $window->isEmpty();

                return [
                    'number' => $installment->number,
                    'dueDate' => $installment->due_date->toDateString(),
                    'dueDateLabel' => $installment->due_date->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
                    'amount' => $this->money($installment->amount_cents),
                    'paid' => $this->money($installment->paid_cents),
                    'statusLabel' => $installment->status->label(),
                    'reschedule' => $movable ? [
                        'url' => route('installments.reschedule', $installment),
                        'earliest' => $window->earliest->toDateString(),
                        'latest' => $window->latest?->toDateString(),
                        'window' => $window->describe(),
                    ] : null,
                ];
            }, $installments),
            'payments' => $payments
                ->map(fn (Payment $payment): array => (new PaymentRowResource($payment, $timezone))->resolve($request))
                ->values()
                ->all(),
            'reallocation' => $this->reallocationOptions($layaway, $installments, $payments->all(), $timezone),
            'manualPayment' => $layaway->status->acceptsPayments() ? $this->manualPaymentOptions($layaway, $timezone) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function manualPaymentOptions(Layaway $layaway, DateTimeZone $timezone): array
    {
        [$min, $max] = StoreReceiptRequest::boundsFor($layaway, $timezone);
        $rate = $this->rates->current($this->context->organization());

        return [
            'storeUrl' => route('layaways.payments.store', $layaway),
            'previewUrl' => route('layaways.payments.preview', $layaway),
            'methods' => array_map(fn (PaymentMethod $method): array => [
                'value' => $method->value,
                'label' => $method->label(),
                'defaultCurrency' => $method->defaultCurrency()->value,
                'requiresReference' => $method->requiresReference(),
            ], PaymentMethod::cases()),
            'paymentAccounts' => PaymentAccount::query()->orderBy('position')->get()
                ->map(fn (PaymentAccount $account): array => [
                    'id' => (int) $account->getKey(),
                    'methodLabel' => $account->method->label(),
                    'identifier' => $account->identifier,
                    'isActive' => $account->is_active,
                ])
                ->values()
                ->all(),
            'currencies' => array_map(fn (Currency $currency): array => ['value' => $currency->value, 'label' => $currency->label()], Currency::cases()),
            'paidAt' => ['min' => $min, 'max' => $max],
            'rate' => $rate instanceof RateSnapshot ? ['rate' => $rate->rate, 'sourceLabel' => $rate->source->label()] : null,
            'file' => ['maxMb' => ReceiptFile::maxSizeMb(), 'accept' => ReceiptFile::ACCEPT],
        ];
    }

    /**
     * @param  list<Installment>  $installments
     * @param  array<int, Payment>  $payments
     * @return array<string, mixed>|null
     */
    private function reallocationOptions(Layaway $layaway, array $installments, array $payments, DateTimeZone $timezone): ?array
    {
        $allocated = array_values(array_filter($payments, fn (Payment $payment): bool => $payment->allocations->isNotEmpty()));

        if ($allocated === [] || in_array($layaway->status, [LayawayStatus::Cancelled, LayawayStatus::Expired], true)) {
            return null;
        }

        return [
            'installments' => array_map(fn (Installment $installment): array => [
                'id' => (int) $installment->getKey(),
                'number' => $installment->number,
                'amountCents' => $installment->amount_cents->cents,
                'paidCents' => $installment->paid_cents->cents,
            ], $installments),
            'payments' => array_map(fn (Payment $payment): array => [
                'id' => (int) $payment->getKey(),
                'url' => route('payments.reallocate', $payment),
                'label' => sprintf('%s del %s', MoneyFormatter::format($payment->amount_cents_usd), $payment->settled_at->setTimezone($timezone)->locale('es')->isoFormat('D [de] MMMM')),
                'allocations' => $payment->allocations->map(fn (PaymentAllocation $allocation): array => [
                    'installmentId' => (int) $allocation->installment_id,
                    'cents' => $allocation->amount_cents->cents,
                ])->values()->all(),
            ], $allocated),
        ];
    }

    public function cancel(CancelLayawayRequest $request, Layaway $layaway, CancelLayawayAction $cancel): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $cancel->handle($layaway, $actor, $request->reason());
        } catch (IllegalLayawayTransitionException $exception) {
            throw ValidationException::withMessages([
                'reason' => sprintf('Este apartado está %s y ya no se puede cancelar.', mb_strtolower($exception->from->label())),
            ]);
        }

        return to_route('layaways.show', $layaway)->with('status', 'Apartado cancelado. El producto volvió al inventario.');
    }

    public function deliver(Request $request, Layaway $layaway, MarkDeliveredAction $deliver): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $deliver->handle($layaway, $actor);
        } catch (LayawayNotDeliverableException $exception) {
            throw ValidationException::withMessages(['delivery' => $exception->getMessage()]);
        }

        return to_route('layaways.show', $layaway)->with('status', 'Entrega registrada.');
    }

    private function moment(?DateTimeInterface $at, DateTimeZone $timezone): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->setTimezone($timezone)->locale('es')->isoFormat('D [de] MMMM [de] YYYY, h:mm a');
    }
}
