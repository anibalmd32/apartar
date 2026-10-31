<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Layaway\CreateBackofficeLayawayAction;
use App\Actions\Layaway\CreatedLayaway;
use App\Actions\Layaway\CreateLayawayData;
use App\Actions\Layaway\OpenLayawayAction;
use App\Domain\Catalog\InsufficientStockException;
use App\Domain\Catalog\ProductNotAvailableException;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\ManualRate;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\UnconfirmedRateDeviationException;
use App\Domain\Exchange\UnconfirmedStaleRateException;
use App\Domain\Layaway\DownPaymentWindow;
use App\Domain\Layaway\LayawayTerms;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidRateException;
use App\Domain\Money\Money;
use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\IdempotencyKeyReusedException;
use App\Domain\Payment\OpeningPaymentPreview;
use App\Domain\Payment\PaymentAmounts;
use App\Domain\Payment\PaymentConfirmation;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Payment\UnconfirmedExcessException;
use App\Domain\Tenancy\OrganizationCapability;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\ApproveReceiptRequest;
use App\Http\Requests\Backoffice\StoreLayawayRequest;
use App\Http\Resources\LayawayPlanResource;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Product;
use App\Models\User;
use App\Queries\CustomerSearch;
use App\Queries\PaymentFieldOptionsQuery;
use App\Support\DecimalInput;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class LayawayCreationController extends Controller
{
    public const SEARCH_LIMIT = 10;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ExchangeRateService $rates,
        private readonly OpenLayawayAction $opening,
        private readonly PaymentFieldOptionsQuery $paymentFields,
    ) {}

    public function create(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('create', Layaway::class);

        $products = $this->products();
        $product = $this->selectedProduct($request, $products);
        $quantity = $this->quantityFrom($request, $product);
        $today = CarbonImmutable::now($this->timezone())->toDateString();

        return Inertia::render('backoffice/layaways/Create', [
            'products' => array_map(fn (Product $option): array => [
                'id' => (int) $option->getKey(),
                'name' => $option->name,
                'sku' => $option->sku,
                'stock' => $option->stock,
                'maxQuantity' => min($option->stock, CreateLayawayData::MAX_QUANTITY),
            ], $products),
            'selection' => [
                'product' => $product === null ? null : (int) $product->getKey(),
                'quantity' => $quantity,
            ],
            'plan' => fn (): ?array => $product === null ? null : $this->plan($request, $product, $quantity),
            'customer' => $this->preselectedCustomer($request),
            'window' => [
                'defaultHours' => $this->opening->downPaymentWindowHours(),
                'maxHours' => DownPaymentWindow::MAX_HOURS,
                'maxDays' => DownPaymentWindow::MAX_DAYS,
            ],
            'payment' => $this->paymentFields->for($today, $today),
            'urls' => [
                'store' => route('layaways.store'),
                'customers' => route('layaways.create.customers'),
                'paymentPreview' => route('layaways.create.payment-preview'),
            ],
        ]);
    }

    public function store(StoreLayawayRequest $request, CreateBackofficeLayawayAction $create): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->toData();

        try {
            $result = $create->handle($data, $actor);
        } catch (ProductNotAvailableException) {
            return back()->withErrors(['product' => sprintf('«%s» no está publicado: publícalo para apartarlo.', $data->product->name)]);
        } catch (InsufficientStockException $exception) {
            return back()->withErrors(['quantity' => $exception->getMessage()]);
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

        return to_route('layaways.show', $result->layaway)->with('status', $this->confirmationOf($result));
    }

    public function customers(Request $request): JsonResponse
    {
        $this->authorizeCustomerSearch($request);

        $search = CustomerSearch::from($request->query('q'));

        if (! $search instanceof CustomerSearch) {
            return response()->json(['customers' => []]);
        }

        $customers = $search->apply(Customer::query())
            ->orderBy('full_name')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->map(fn (Customer $customer): array => $this->option($customer))
            ->all();

        return response()->json(['customers' => $customers]);
    }

    public function paymentPreview(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', Layaway::class);

        $product = Product::query()->published()->find(filter_var($request->query('product'), FILTER_VALIDATE_INT) ?: 0);
        $quantity = filter_var($request->query('quantity'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => CreateLayawayData::MAX_QUANTITY]]);
        $amount = $request->query('amount');
        $amount = is_string($amount) ? DecimalInput::normalize($amount) : $amount;
        $currency = Currency::tryFrom(is_string($request->query('currency')) ? $request->query('currency') : '');
        $rate = $request->query('rate');
        $rate = is_string($rate) && $rate !== '' && $currency !== Currency::USD ? $rate : null;

        if (! $product instanceof Product || ! is_int($quantity)) {
            return response()->json(['message' => 'Elige primero el producto y la cantidad.'], 422);
        }

        if (! is_string($amount) || preg_match(ApproveReceiptRequest::AMOUNT_PATTERN, $amount) !== 1) {
            return response()->json(['message' => 'Escribe el importe con punto decimal y hasta dos decimales.'], 422);
        }

        if (! $currency instanceof Currency) {
            return response()->json(['message' => 'Elige la moneda del pago.'], 422);
        }

        if ($rate !== null && preg_match(ApproveReceiptRequest::RATE_PATTERN, $rate) !== 1) {
            return response()->json(['message' => 'Escribe la tasa con punto decimal y hasta ocho decimales.'], 422);
        }

        try {
            PaymentAmounts::assertPositive($amount, $currency, 'del pago');
            $settled = Money::fromDecimalString($amount, $currency);
            $snapshot = $rate !== null ? new ManualRate($rate) : $this->rates->current($this->context->organization());
            $preview = app(OpeningPaymentPreview::class)->for($this->termsOf($product, $quantity), $settled, $snapshot);
        } catch (InvalidArgumentException|InvalidRateException|PaymentNotApplicableException|MissingExchangeRateException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['preview' => $preview->toArray()]);
    }

    private function confirmationOf(CreatedLayaway $result): string
    {
        $message = sprintf('Apartado %s creado para %s.', $result->layaway->code, $result->customer->full_name);

        return $result->payment === null ? $message : $message.' '.PaymentConfirmation::of($result->payment);
    }

    /**
     * @return list<Product>
     */
    private function products(): array
    {
        return Product::query()
            ->published()
            ->where('stock', '>', 0)
            ->orderBy('name')
            ->get(['id', 'organization_id', 'name', 'sku', 'stock'])
            ->all();
    }

    /**
     * @param  list<Product>  $products
     */
    private function selectedProduct(Request $request, array $products): ?Product
    {
        $id = filter_var($request->query('producto'), FILTER_VALIDATE_INT);

        if (! is_int($id)) {
            return null;
        }

        foreach ($products as $product) {
            if ((int) $product->getKey() === $id) {
                return Product::query()->find($id);
            }
        }

        return null;
    }

    private function quantityFrom(Request $request, ?Product $product): int
    {
        $raw = $request->query('cantidad');
        $quantity = is_numeric($raw) ? (int) $raw : 1;
        $max = $product === null ? CreateLayawayData::MAX_QUANTITY : min(max(1, $product->stock), CreateLayawayData::MAX_QUANTITY);

        return max(1, min($quantity, $max));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function plan(Request $request, Product $product, int $quantity): ?array
    {
        try {
            $terms = $this->termsOf($product, $quantity);
        } catch (InvalidArgumentException) {
            return null;
        }

        return [
            ...(new LayawayPlanResource($terms, $this->timezone()))->resolve($request),
            'productName' => $product->name,
        ];
    }

    private function termsOf(Product $product, int $quantity): LayawayTerms
    {
        return new LayawayTerms(
            unitPrice: $product->price_cents,
            downPayment: $product->down_payment_cents,
            quantity: $quantity,
            installmentsCount: $product->installments_count,
            frequencyDays: $product->frequency_days,
        );
    }

    /**
     * @return array{id: int, name: string, detail: string|null}|null
     */
    private function preselectedCustomer(Request $request): ?array
    {
        $id = filter_var($request->query('cliente'), FILTER_VALIDATE_INT);
        $customer = is_int($id) ? Customer::query()->find($id) : null;

        return $customer instanceof Customer ? $this->option($customer) : null;
    }

    /**
     * @return array{id: int, name: string, detail: string|null}
     */
    private function option(Customer $customer): array
    {
        return [
            'id' => (int) $customer->getKey(),
            'name' => $customer->full_name,
            'detail' => implode(' · ', array_filter([$customer->document_number, $customer->phone])),
        ];
    }

    private function authorizeCustomerSearch(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User
                && $user->can('create', Layaway::class)
                && $user->role->can(OrganizationCapability::Customers),
            403,
        );
    }

    private function timezone(): DateTimeZone
    {
        return new DateTimeZone($this->context->organization()->timezone);
    }
}
