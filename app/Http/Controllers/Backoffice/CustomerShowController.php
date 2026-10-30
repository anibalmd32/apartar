<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Http\Resources\PaymentRowResource;
use App\Models\AuditEntry;
use App\Models\Catalog;
use App\Models\CatalogSubscription;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerMerge;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\Receipt;
use App\Models\User;
use App\Queries\CustomerBalanceQuery;
use App\Support\DocumentNumber;
use App\Support\PhoneNumber;
use App\Support\SignedFileUrl;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerShowController extends Controller
{
    use ProjectsMoney;

    public const LIST_LIMIT = 20;

    public const LIST_MAX = 200;

    public function __construct(
        private readonly TenantContext $context,
        private readonly CustomerBalanceQuery $balances,
    ) {}

    public function __invoke(Request $request, Customer $customer): Response
    {
        Gate::forUser($request->user())->authorize('view', $customer);

        $found = $customer;
        $timezone = new DateTimeZone($this->context->organization()->timezone);
        $balance = $this->balances->forCustomer($found);
        $limit = $this->listLimit($request);
        $layaways = Layaway::query()->where('customer_id', $found->getKey())->with('catalog:id,organization_id,name')->latest('id')->get();
        $cancelledBy = $this->cancelledBy($layaways->modelKeys());
        $historyTotal = $layaways->filter(fn (Layaway $layaway): bool => $layaway->status->isTerminal())->count();
        $paymentsTotal = Payment::query()->whereIn('layaway_id', $layaways->modelKeys())->count();
        $receiptsTotal = Receipt::query()->where('customer_id', $found->getKey())->count();

        return Inertia::render('backoffice/customers/Show', [
            'customer' => $this->profileOf($found, $timezone),
            'totals' => [
                'committed' => $this->money($balance->committed()),
                'paid' => $this->money($balance->paid()),
                'remaining' => $this->money($balance->remaining()),
                'overdueInstallments' => $balance->overdueInstallments(),
                'overdueAmount' => $this->money($balance->overdueAmount()),
                'nextDueDate' => $balance->nextDueDate()?->toDateString(),
            ],
            'layaways' => [
                'active' => $layaways->filter(fn (Layaway $layaway): bool => ! $layaway->status->isTerminal())->map(fn (Layaway $layaway): array => $this->layawayRow($layaway, $timezone, $cancelledBy))->values()->all(),
                'history' => $layaways->filter(fn (Layaway $layaway): bool => $layaway->status->isTerminal())->take($limit)->map(fn (Layaway $layaway): array => $this->layawayRow($layaway, $timezone, $cancelledBy))->values()->all(),
                'historyTotal' => $historyTotal,
            ],
            'lists' => [
                'paymentsTotal' => $paymentsTotal,
                'receiptsTotal' => $receiptsTotal,
                'moreUrl' => max($historyTotal, $paymentsTotal, $receiptsTotal) > $limit && $limit < self::LIST_MAX
                    ? route('customers.show', [$found, 'mostrar' => $limit + self::LIST_LIMIT])
                    : null,
            ],
            'payments' => Payment::query()
                ->whereIn('layaway_id', $layaways->modelKeys())
                ->with(['allocations.installment', 'receipt'])
                ->latest('settled_at')
                ->latest('id')
                ->limit($limit)
                ->get()
                ->map(fn (Payment $payment): array => (new PaymentRowResource($payment, $timezone))->resolve($request) + ['layawayCode' => $layaways->firstWhere('id', $payment->layaway_id)?->code])
                ->all(),
            'receipts' => Receipt::query()
                ->where('customer_id', $found->getKey())
                ->with(['paymentAccount', 'reviewer:id,organization_id,name', 'layaway:id,organization_id,code'])
                ->latest('id')
                ->limit($limit)
                ->get()
                ->map(fn (Receipt $receipt): array => $this->receiptRow($receipt, $timezone))
                ->all(),
            'conversationId' => Conversation::query()->where('customer_id', $customer->getKey())->value('id'),
            'subscriptions' => $customer->account_id === null ? [] : CatalogSubscription::query()
                ->where('account_id', $customer->account_id)
                ->whereIn('catalog_id', Catalog::query()->select('id'))
                ->join('catalogs', 'catalogs.id', '=', 'catalog_subscriptions.catalog_id')
                ->orderBy('catalogs.name')
                ->get(['catalogs.name', 'catalog_subscriptions.subscribed_at', 'catalog_subscriptions.muted_at'])
                ->map(fn (CatalogSubscription $subscription): array => [
                    'name' => (string) $subscription->getAttribute('name'),
                    'since' => $this->day($subscription->subscribed_at, $timezone),
                    'muted' => $subscription->muted_at !== null,
                ])
                ->all(),
            'merge' => [
                'url' => route('customers.merge', $found),
                'candidatesUrl' => route('customers.merge.candidates', $found),
            ],
        ]);
    }

    private function listLimit(Request $request): int
    {
        $asked = filter_var($request->query('mostrar'), FILTER_VALIDATE_INT, ['options' => ['min_range' => self::LIST_LIMIT, 'max_range' => self::LIST_MAX]]);

        return is_int($asked) ? intdiv($asked, self::LIST_LIMIT) * self::LIST_LIMIT : self::LIST_LIMIT;
    }

    public static function redirectMerged(Request $request): RedirectResponse
    {
        $id = $request->route('customer');
        $survivor = is_numeric($id) ? CustomerMerge::survivorOf((int) $id) : null;

        abort_unless($survivor instanceof Customer, 404);

        return to_route('customers.show', $survivor)->with('status', sprintf('Esa ficha se fusionó con la de %s: esta es ahora su ficha.', $survivor->full_name));
    }

    /**
     * @return array<string, mixed>
     */
    private function profileOf(Customer $customer, DateTimeZone $timezone): array
    {
        $document = DocumentNumber::tryFromInput($customer->document_number);
        $phone = PhoneNumber::tryFromInput($customer->phone);

        return [
            'id' => (int) $customer->getKey(),
            'name' => $customer->full_name,
            'document' => $document instanceof DocumentNumber ? $document->formatted() : $customer->document_number,
            'phone' => $customer->phone,
            'phoneLabel' => $phone instanceof PhoneNumber ? $phone->formatted() : $customer->phone,
            'email' => $customer->email,
            'createdAt' => $this->day($customer->created_at, $timezone),
            'termsAcceptedAt' => $this->day($customer->terms_accepted_at, $timezone),
            'termsDeclaredBy' => $customer->terms_declared_by_user_id === null ? null : User::query()->whereKey($customer->terms_declared_by_user_id)->value('name'),
            'hasLinkedAccount' => $customer->account_id !== null,
        ];
    }

    /**
     * @param  array<int, string>  $cancelledBy
     * @return array<string, mixed>
     */
    private function layawayRow(Layaway $layaway, DateTimeZone $timezone, array $cancelledBy): array
    {
        return [
            'code' => $layaway->code,
            'url' => route('layaways.show', $layaway),
            'status' => $layaway->status->value,
            'statusLabel' => $layaway->status->label(),
            'productName' => $layaway->product_name_snapshot,
            'catalogName' => $layaway->catalog?->name,
            'total' => $this->money($layaway->total_cents),
            'paid' => $this->money($layaway->paid_cents),
            'remaining' => $this->money($layaway->remaining_cents),
            'nextDueDate' => $layaway->next_due_date?->toDateString(),
            'createdAt' => $this->day($layaway->created_at, $timezone),
            'cancellationReason' => $layaway->cancellation_reason,
            'cancelledBy' => $cancelledBy[(int) $layaway->getKey()] ?? null,
            'resendCodeUrl' => $layaway->status->isTerminal() ? null : route('layaways.resend-code', $layaway),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptRow(Receipt $receipt, DateTimeZone $timezone): array
    {
        $account = $receipt->paymentAccount;
        $reviewer = $receipt->reviewer;

        return [
            'id' => (int) $receipt->getKey(),
            'url' => route('receipts.show', $receipt),
            'submittedAt' => $this->day($receipt->created_at, $timezone),
            'layawayCode' => $receipt->layaway?->code,
            'statusLabel' => $receipt->status->label(),
            'declared' => MoneyFormatter::format(Money::fromDecimalString((string) $receipt->declared_amount, $receipt->declared_currency)),
            'paidToAccount' => $account instanceof PaymentAccount ? implode(' · ', array_filter([$account->method->label(), $account->bank, $account->identifier])) : null,
            'rejectionReason' => $receipt->status === ReceiptStatus::Rejected ? ($receipt->rejection_reason ?? $receipt->rejection_reason_code?->label()) : null,
            'reviewedBy' => $reviewer instanceof User ? $reviewer->name : null,
            'fileUrl' => SignedFileUrl::forBackoffice($receipt),
        ];
    }

    /**
     * @param  array<int, mixed>  $layawayIds
     * @return array<int, string>
     */
    private function cancelledBy(array $layawayIds): array
    {
        if ($layawayIds === []) {
            return [];
        }

        $names = [];

        foreach (AuditEntry::query()
            ->where('event', AuditEvent::LayawayCancelled->value)
            ->where('subject_type', Layaway::class)
            ->whereIn('subject_id', $layawayIds)
            ->with('causer')
            ->get() as $entry) {
            $causer = $entry->causer;
            $names[(int) $entry->subject_id] = $causer instanceof User ? $causer->name : 'El sistema';
        }

        return $names;
    }

    private function day(?DateTimeInterface $at, DateTimeZone $timezone): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->setTimezone($timezone)->toDateString();
    }
}
