<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Payment\PaymentAccountView;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptInboxSort;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Concerns\ResolvesTableState;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\PaymentAccount;
use App\Models\Receipt;
use App\Support\BusinessDay;
use App\Support\SignedFileUrl;
use App\Support\TableState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ReceiptInboxController extends Controller
{
    use ResolvesTableState;

    public const OVERDUE_AFTER_HOURS = 48;

    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', Receipt::class);

        $state = $this->tableState($request, ReceiptInboxSort::whitelist());
        $receipts = $this->paginateTable($this->query($request, $state), $state, simple: true);
        $now = CarbonImmutable::now();

        return Inertia::render('backoffice/receipts/Inbox', [
            'rows' => $this->rows($receipts, $now),
            'now' => $now->toIso8601String(),
            'meta' => $this->tableMeta($receipts, $state),
            'newestPending' => $this->newestPendingId(),
            'options' => fn (): array => [
                'statuses' => array_map(fn (ReceiptStatus $status): array => ['value' => $status->value, 'label' => $status->label()], ReceiptStatus::cases()),
                'methods' => array_map(fn (PaymentMethod $method): array => ['value' => $method->value, 'label' => $method->label()], PaymentMethod::cases()),
                'banks' => $this->banks(),
                'paymentAccounts' => $this->paymentAccounts(),
            ],
            'statusCounts' => fn (): array => $this->statusCounts(),
            'overdueAfterHours' => self::OVERDUE_AFTER_HOURS,
            'timezone' => $this->context->organization()->timezone,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $counts = Receipt::query()
            ->whereHas('layaway', fn (Builder $layaway) => $layaway->where('status', '!=', LayawayStatus::Cancelled->value))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $result = [];

        foreach (ReceiptStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }

    public function pending(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', Receipt::class);

        return response()->json([
            'pending' => Receipt::query()->pendingReview()->count(),
            'newest' => $this->newestPendingId(),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function newestPendingId(): ?int
    {
        $id = Receipt::query()->pendingReview()->max('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param TableState<*> $state
     * @return Builder<Receipt>
     */
    private function query(Request $request, TableState $state): Builder
    {
        $query = Receipt::query()->with([
            'layaway:id,organization_id,code,status',
            'customer:id,organization_id,full_name,document_number',
            'paymentAccount',
        ]);

        $status = $state->filter('status', $request->query('status'), fn (mixed $raw): ?ReceiptStatus => is_string($raw) ? ReceiptStatus::tryFrom($raw) : null);

        if ($status instanceof ReceiptStatus) {
            $query->where('status', $status->value);
        }

        $method = $state->filter('method', $request->query('method'), fn (mixed $raw): ?PaymentMethod => is_string($raw) ? PaymentMethod::tryFrom($raw) : null);

        if ($method instanceof PaymentMethod) {
            $query->where('payment_method', $method->value);
        }

        $bank = $state->filter('bank', $request->query('bank'), fn (mixed $raw): ?string => $this->knownBank($raw));

        if ($bank !== null) {
            $query->where('bank', $bank);
        }

        $account = $state->filter('account', $request->query('account'), fn (mixed $raw): ?int => $this->ownAccountId($raw));

        if ($account !== null) {
            $query->where('payment_account_id', $account);
        }

        $this->applyReceivedRange($query, $request, $state);

        $withCancelled = $state->filter('cancelled', $request->query('cancelled'), fn (mixed $raw): ?bool => $raw === '1' ? true : null);

        if ($withCancelled !== true) {
            $query->whereHas('layaway', fn (Builder $layaway) => $layaway->where('status', '!=', LayawayStatus::Cancelled->value));
        }

        $sort = $state->sort;

        if ($sort instanceof ReceiptInboxSort) {
            return $query->orderBy($sort->column(), $state->direction())->orderBy('receipts.id', $state->direction());
        }

        return $this->fifo($query);
    }

    /**
     * @param  Builder<Receipt>  $query
     * @return Builder<Receipt>
     */
    private function fifo(Builder $query): Builder
    {
        $pending = ReceiptStatus::PendingReview->value;

        return $query
            ->orderByRaw('CASE WHEN receipts.status = ? THEN 0 ELSE 1 END', [$pending])
            ->orderByRaw('CASE WHEN receipts.status = ? THEN receipts.created_at END ASC', [$pending])
            ->orderByDesc('receipts.created_at')
            ->orderBy('receipts.id');
    }

    private function knownBank(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '' || mb_strlen($raw) > 100) {
            return null;
        }

        return Receipt::query()->where('bank', $raw)->exists() ? $raw : null;
    }

    private function ownAccountId(mixed $raw): ?int
    {
        if (! is_string($raw) || ! ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }

        $id = PaymentAccount::query()->whereKey((int) $raw)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param  Builder<Receipt>  $query
     * @param TableState<*> $state
     */
    private function applyReceivedRange(Builder $query, Request $request, TableState $state): void
    {
        $timezone = $this->context->organization()->timezone;
        $asDate = fn (CarbonImmutable $date): string => $date->toDateString();

        $from = $state->filter('from', $request->query('from'), fn (mixed $raw): ?CarbonImmutable => BusinessDay::parse($raw, $timezone), $asDate);

        if ($from instanceof CarbonImmutable) {
            $query->where('receipts.created_at', '>=', $from->startOfDay()->setTimezone(config('app.timezone')));
        }

        $to = $state->filter('to', $request->query('to'), fn (mixed $raw): ?CarbonImmutable => BusinessDay::parse($raw, $timezone), $asDate);

        if ($to instanceof CarbonImmutable) {
            $query->where('receipts.created_at', '<=', $to->endOfDay()->setTimezone(config('app.timezone')));
        }
    }

    /**
     * @param  Paginator<int, Receipt>  $receipts
     * @return list<array<string, mixed>>
     */
    private function rows(Paginator $receipts, CarbonImmutable $now): array
    {
        $overdueBefore = $now->subHours(self::OVERDUE_AFTER_HOURS);

        return array_map(function (Receipt $receipt) use ($overdueBefore): array {
            $layaway = $receipt->layaway;
            $customer = $receipt->customer;
            $account = $receipt->paymentAccount;
            $pending = $receipt->status->awaitsReview();

            return [
                'id' => (int) $receipt->getKey(),
                'reviewUrl' => route('receipts.show', $receipt),
                'status' => $receipt->status->value,
                'statusLabel' => $receipt->status->label(),
                'overdue' => $pending && $receipt->created_at !== null && $receipt->created_at->lessThan($overdueBefore),
                'submittedAt' => $receipt->created_at?->toIso8601String(),
                'paidAt' => $receipt->paid_at->toDateString(),
                'layaway' => $layaway instanceof Layaway ? [
                    'code' => $layaway->code,
                    'url' => route('layaways.show', $layaway),
                    'cancelled' => $layaway->status === LayawayStatus::Cancelled,
                ] : null,
                'customer' => $customer instanceof Customer ? [
                    'fullName' => $customer->full_name,
                    'documentNumber' => $customer->document_number,
                ] : null,
                'method' => $receipt->payment_method->value,
                'methodLabel' => $receipt->payment_method->label(),
                'bank' => $receipt->bank,
                'reference' => $receipt->reference,
                'paymentAccount' => $account instanceof PaymentAccount ? PaymentAccountView::of($account)->toArray() : null,
                'declaredAmount' => (string) $receipt->declared_amount,
                'declaredCurrency' => $receipt->declared_currency->value,
                'file' => [
                    'url' => SignedFileUrl::forBackoffice($receipt),
                    'kind' => $receipt->file_mime === 'application/pdf' ? 'pdf' : 'image',
                ],
            ];
        }, $receipts->items());
    }

    /**
     * @return list<string>
     */
    private function banks(): array
    {
        /** @var list<string> $banks */
        $banks = Receipt::query()->whereNotNull('bank')->distinct()->orderBy('bank')->pluck('bank')->all();

        return $banks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paymentAccounts(): array
    {
        return PaymentAccount::query()
            ->orderBy('position')
            ->get()
            ->map(fn (PaymentAccount $account): array => PaymentAccountView::of($account)->toArray())
            ->values()
            ->all();
    }
}
