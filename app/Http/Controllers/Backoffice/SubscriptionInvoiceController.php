<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Subscription\SubmitSubscriptionReceiptAction;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\InvoiceNotPayableException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\SubmitSubscriptionReceiptRequest;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionReceipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class SubscriptionInvoiceController extends Controller
{
    public function index(TenantContext $context, ExchangeRateService $rates): Response
    {
        Gate::authorize('viewInvoices', Subscription::query()->firstOrFail());

        $organization = $context->organization();
        $today = CarbonImmutable::now($organization->timezone);
        $rate = $rates->current(null);

        $invoices = SubscriptionInvoice::query()
            ->with(['plan', 'receipts' => fn ($query) => $query->latest('id')])
            ->latest('issued_at')
            ->latest('id')
            ->get();

        $sequence = $invoices->sortBy('id')->values()->mapWithKeys(fn (SubscriptionInvoice $invoice, int $index): array => [$invoice->getKey() => $index + 1]);

        return Inertia::render('backoffice/subscription/Invoices', [
            'invoices' => $invoices->map(fn (SubscriptionInvoice $invoice): array => $this->row($invoice, $today, $rate, (int) $sequence[$invoice->getKey()]))->values()->all(),
            'platformAccounts' => $this->platformAccounts(),
            'methods' => array_map(fn (PaymentMethod $method): array => [
                'value' => $method->value,
                'label' => $method->label(),
                'requiresReference' => $method->requiresReference(),
                'defaultCurrency' => $method->defaultCurrency()->value,
            ], PaymentMethod::cases()),
            'currencies' => array_map(fn (Currency $currency): array => ['value' => $currency->value, 'label' => $currency->label()], Currency::cases()),
            'file' => ['maxMb' => ReceiptFile::maxSizeMb(), 'accept' => ReceiptFile::ACCEPT],
            'subscriptionUrl' => route('subscription.show'),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function storeReceipt(SubmitSubscriptionReceiptRequest $request, SubscriptionInvoice $invoice, SubmitSubscriptionReceiptAction $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $action->handle($request->toData($invoice), $actor);
        } catch (DuplicateReceiptException $exception) {
            throw ValidationException::withMessages([$exception->field() => $exception->getMessage()]);
        } catch (InvoiceNotPayableException $exception) {
            throw ValidationException::withMessages(['invoice' => $exception->getMessage()]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return to_route('subscription.invoices.index')->with('status', 'Recibimos tu comprobante. Lo revisamos y aquí verás el resultado.');
    }

    /**
     * @return list<array{method: string, detail: string}>
     */
    private function platformAccounts(): array
    {
        /** @var list<array{method: string, detail: string|null}> $accounts */
        $accounts = config('platform.billing_accounts', []);

        return array_values(array_map(
            fn (array $account): array => ['method' => rtrim($account['method'], ' ·'), 'detail' => (string) $account['detail']],
            array_filter($accounts, fn (array $account): bool => is_string($account['detail']) && $account['detail'] !== ''),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SubscriptionInvoice $invoice, CarbonImmutable $today, ?RateSnapshot $rate, int $sequence): array
    {
        $status = $invoice->displayStatus($today);
        $open = $status->acceptsReceipts();
        $lastReceipt = $open ? $invoice->receipts->first() : null;

        return [
            'id' => $invoice->getKey(),
            'number' => SubscriptionInvoice::formatNumber($invoice->issued_at, $sequence),
            'planName' => $invoice->plan->name,
            'kind' => $invoice->kind->label(),
            'periodStart' => $invoice->period_start->toIso8601String(),
            'periodEnd' => $invoice->period_end->toIso8601String(),
            'amount' => $this->money($invoice->amount(), $open ? $rate : null),
            'outstanding' => $invoice->paid_cents > 0 && $invoice->outstanding()->cents > 0 ? $this->money($invoice->outstanding(), $rate) : null,
            'status' => $status->value,
            'dueDate' => $invoice->due_date->toDateString(),
            'receiptStatus' => match (true) {
                ! $lastReceipt instanceof SubscriptionReceipt, $lastReceipt->status === ReceiptStatus::Approved => 'none',
                $lastReceipt->status === ReceiptStatus::PendingReview => 'pending_review',
                default => 'rejected',
            },
            'rejectionReason' => $lastReceipt?->status === ReceiptStatus::Rejected ? $lastReceipt->rejection_reason : null,
            'payUrl' => $open ? route('subscription.invoices.receipt', $invoice) : null,
        ];
    }

    /**
     * @return array{cents: int, usd: string, ves: string|null}
     */
    private function money(Money $amount, ?RateSnapshot $rate): array
    {
        return [
            'cents' => $amount->cents,
            'usd' => MoneyFormatter::format($amount),
            'ves' => $rate === null ? null : MoneyFormatter::format($amount->convertTo(Currency::VES, $rate)),
        ];
    }
}
