<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Actions\Layaway\CreateLayawayAction;
use App\Actions\Layaway\CreateLayawayData;
use App\Domain\Catalog\CatalogExpiredException;
use App\Domain\Catalog\CatalogUnavailableException;
use App\Domain\Catalog\InsufficientStockException;
use App\Domain\Catalog\ProductNotAvailableException;
use App\Domain\Catalog\PublicCatalogAccess;
use App\Domain\Layaway\LayawayTerms;
use App\Domain\Layaway\NotAcceptingLayawaysException;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreLayawayRequest;
use App\Http\Resources\CatalogViewResource;
use App\Http\Resources\LayawayPlanResource;
use App\Http\Resources\ProductDetailResource;
use App\Jobs\LinkVisitorEventsToCustomer;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Product;
use App\Notifications\LayawayConfirmationNotification;
use App\Queries\ActivePaymentAccountsQuery;
use App\Queries\CatalogProductsQuery;
use App\Support\InvalidDocumentNumberException;
use App\Support\InvalidPhoneNumberException;
use DateTimeZone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class LayawayController extends Controller
{
    public const SESSION_KEY = 'layaways.confirmed';

    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
        private readonly CatalogProductsQuery $products,
        private readonly ActivePaymentAccountsQuery $paymentAccounts,
    ) {}

    /**
     * @throws ModelNotFoundException<Catalog>
     * @throws CatalogExpiredException
     */
    public function create(Request $request, Catalog $catalog, Product $product): Response
    {
        PublicCatalogAccess::ensureOpen($catalog);

        $product->load(['category', 'tags', 'media', 'specifications']);

        $detail = (new ProductDetailResource($product, $catalog, $this->paymentAccounts->all(), $this->limits->offersLayaways()))->resolve($request);
        $maxQuantity = max(1, (int) $detail['maxQuantity']);
        $quantity = $this->quantityFrom($request, $maxQuantity);

        return Inertia::render('catalog/LayawayCreate', [
            'catalog' => CatalogViewResource::forVisitor($catalog, $this->limits->plan(), $this->products)->resolve($request),
            'product' => $detail,
            'plan' => fn (): ?array => $this->plan($request, $product, $quantity),
            'quantity' => $quantity,
            'consent' => ['businessName' => $catalog->organization->name],
            'account' => $this->accountPrefill(),
        ]);
    }

    /**
     * @return array{fullName: string, email: string}|null
     */
    private function accountPrefill(): ?array
    {
        $account = Auth::guard('account')->user();

        return $account instanceof Account ? ['fullName' => $account->display_name, 'email' => $account->email] : null;
    }

    /**
     * @throws ValidationException
     */
    public function store(StoreLayawayRequest $request, Catalog $catalog, Product $product, CreateLayawayAction $create): RedirectResponse
    {
        try {
            $layaway = $create->handle($request->toData($catalog, $product));
        } catch (CatalogUnavailableException|NotAcceptingLayawaysException $exception) {
            throw ValidationException::withMessages(['catalog' => $exception->getMessage()]);
        } catch (ProductNotAvailableException $exception) {
            throw ValidationException::withMessages(['product' => $exception->getMessage()]);
        } catch (InsufficientStockException $exception) {
            throw ValidationException::withMessages(['quantity' => $exception->getMessage()]);
        } catch (InvalidDocumentNumberException $exception) {
            throw ValidationException::withMessages(['documentNumber' => $exception->getMessage()]);
        } catch (InvalidPhoneNumberException $exception) {
            throw ValidationException::withMessages(['phone' => $exception->getMessage()]);
        }

        $request->session()->push(self::SESSION_KEY, $layaway->code);

        if ($layaway->wasRecentlyCreated) {
            $this->sendConfirmation($catalog, $layaway);
        }

        $customer = $layaway->customer;

        if ($customer instanceof Customer) {
            LinkVisitorEventsToCustomer::dispatchFor($request, $customer);
        }

        return to_route('catalog.layaway.thanks', ['catalog' => $catalog->slug, 'code' => $layaway->code]);
    }

    public function thanks(Request $request, Catalog $catalog, string $code): Response
    {
        $confirmed = $request->session()->get(self::SESSION_KEY, []);

        if (! is_array($confirmed) || ! in_array($code, $confirmed, true)) {
            abort(404);
        }

        $layaway = Layaway::query()->where('code', $code)->firstOrFail();
        $customer = Customer::query()->find($layaway->customer_id);
        $terms = $layaway->terms();

        return Inertia::render('catalog/LayawayThanks', [
            'catalog' => CatalogViewResource::forVisitor($catalog, $this->limits->plan(), $this->products)->resolve($request),
            'layaway' => [
                'code' => $layaway->code,
                'productName' => $layaway->product_name_snapshot,
                'plan' => (new LayawayPlanResource($terms, $this->timezone()))->resolve($request),
                'expiresAt' => $this->expiresAt($layaway),
                'hasEmail' => $customer?->email !== null,
            ],
            'paymentAccounts' => array_map(fn ($account): array => $account->toArray(), $this->paymentAccounts->all()),
            'receiptUrl' => route('portal.receipts.create', ['code' => $layaway->code]),
            'follow' => $this->followState($catalog),
        ]);
    }

    /**
     * @return array{unfollowUrl: string}|null
     */
    private function followState(Catalog $catalog): ?array
    {
        $account = Auth::guard('account')->user();

        if (! $account instanceof Account || ! $account->subscriptions()->where('catalog_id', $catalog->getKey())->exists()) {
            return null;
        }

        return ['unfollowUrl' => route('catalog.unfollow', ['catalog' => $catalog->slug])];
    }

    private function quantityFrom(Request $request, int $maxQuantity): int
    {
        $raw = $request->query('quantity');
        $quantity = is_numeric($raw) ? (int) $raw : 1;

        return max(1, min($quantity, $maxQuantity, CreateLayawayData::MAX_QUANTITY));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function plan(Request $request, Product $product, int $quantity): ?array
    {
        try {
            $terms = new LayawayTerms(
                unitPrice: $product->price_cents,
                downPayment: $product->down_payment_cents,
                quantity: $quantity,
                installmentsCount: $product->installments_count,
                frequencyDays: $product->frequency_days,
            );
        } catch (InvalidArgumentException) {
            return null;
        }

        return (new LayawayPlanResource($terms, $this->timezone()))->resolve($request);
    }

    private function sendConfirmation(Catalog $catalog, Layaway $layaway): void
    {
        $email = Customer::query()->whereKey($layaway->customer_id)->value('email');

        if (! is_string($email) || $email === '') {
            return;
        }

        $terms = $layaway->terms();

        Notification::route('mail', $email)->notify(new LayawayConfirmationNotification(
            code: $layaway->code,
            businessName: $catalog->organization->name,
            productName: $layaway->product_name_snapshot,
            quantity: $terms->quantity,
            total: MoneyFormatter::format($terms->total()),
            downPayment: MoneyFormatter::format($terms->totalDownPayment()),
            expiresAt: $this->expiresAt($layaway) ?? '',
            paymentAccounts: $this->paymentAccounts->all(),
        ));
    }

    private function expiresAt(Layaway $layaway): ?string
    {
        return $layaway->expires_at
            ?->setTimezone($this->timezone())
            ->locale('es')
            ->isoFormat('D [de] MMMM [a las] h:mm a');
    }

    private function timezone(): DateTimeZone
    {
        return new DateTimeZone($this->context->organization()->timezone);
    }
}
