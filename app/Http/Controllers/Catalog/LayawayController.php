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
use App\Http\Controllers\Marketing\LegalController;
use App\Http\Requests\Catalog\StoreLayawayRequest;
use App\Http\Resources\CatalogViewResource;
use App\Http\Resources\LayawayPlanResource;
use App\Http\Resources\ProductDetailResource;
use App\Models\Catalog;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Product;
use App\Notifications\LayawayConfirmationNotification;
use App\Queries\ActivePaymentAccountsQuery;
use App\Queries\CatalogProductsQuery;
use App\Support\InvalidDocumentNumberException;
use App\Support\InvalidPhoneNumberException;
use App\Support\PhoneNumber;
use DateTimeZone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * El formulario de apartado y su confirmación (`REQ-LAY-001` superficie,
 * `REQ-IDN-005`).
 *
 * Es la última milla del producto: catálogo, filtros, ficha y **esto**. La
 * transacción entera la hace `CreateLayawayAction` (`T-045`); aquí solo se
 * pinta, se traduce y se confirma.
 *
 * ## `create` · el formulario
 *
 * Llega por el `{product:slug}` de la ficha, así que un catálogo cerrado o un
 * producto que no está dan el mismo `404`/`410` que la ficha. Lleva el plan
 * **del apartado**, no el de la ficha: la cantidad se elige aquí, y el resumen
 * se recalcula en el servidor sobre el total cada vez que cambia
 * (`LayawayPlanResource`), con una recarga parcial de `plan`.
 *
 * ## `store` · el envío, y por qué no usa el *binding* de la ficha
 *
 * Entre que se abre el formulario y se envía, el negocio puede despublicar el
 * catálogo o retirar el producto (SP-3, SP-4). Con el *binding* de la ficha
 * —que mira el catálogo y la pertenencia—, eso sería un `404` y **el cliente
 * perdería todo lo escrito**. Así que el `POST` resuelve el producto **sin
 * *binding* anidado** (`withoutScopedBindings()`), solo por el global scope, y
 * deja que decida la acción: cada rechazo vuelve como `422` a su campo, y
 * Inertia conserva el resto del formulario. El producto de otra organización
 * no existe para esa consulta, y sigue siendo `404` (`REQ-ORG-002` RB-6).
 *
 * | La acción lanza | Campo |
 * |---|---|
 * | `CatalogUnavailableException` (SP-3, SP-5) | `catalog` |
 * | `NotAcceptingLayawaysException` (SP-2) | `catalog` |
 * | `ProductNotAvailableException` (SP-4) | `product` |
 * | `InsufficientStockException` (SP-1) | `quantity` |
 * | `InvalidDocumentNumberException` (SP-6) | `documentNumber` |
 * | `InvalidPhoneNumberException` (SP-6) | `phone` |
 *
 * Los dos últimos casi nunca llegan: `StoreLayawayRequest` pregunta antes a
 * los mismos objetos. Están por si una regla y su objeto discrepan algún día.
 *
 * ## `thanks` · la confirmación, solo para quien apartó
 *
 * Con el código, lo que se apartó, la inicial y su plazo, y **dónde pagarle al
 * negocio** (RB-10). Se enseña solo a la sesión que creó el apartado: el
 * código es media credencial del portal, y aunque adivinarlo no es viable, la
 * página de gracias no tiene por qué ser una segunda puerta. Recargarla
 * funciona mientras dure la sesión; después, el camino es el portal.
 *
 * ## El correo
 *
 * Solo si hay correo **y** el apartado se acaba de crear
 * (`wasRecentlyCreated`): la acción devuelve el mismo apartado ante una clave
 * repetida, y mandarlo otra vez le escribiría dos veces a quien pulsó dos
 * veces. Va a la cola, y un fallo al enviarlo no toca el apartado (SP-9).
 */
final class LayawayController extends Controller
{
    /** Dónde guarda la sesión los códigos que acaba de crear. */
    public const SESSION_KEY = 'layaways.confirmed';

    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
        private readonly CatalogProductsQuery $products,
        private readonly ActivePaymentAccountsQuery $paymentAccounts,
    ) {}

    /**
     * @throws ModelNotFoundException<Catalog> si el catálogo es un borrador
     * @throws CatalogExpiredException si su campaña terminó
     */
    public function create(Request $request, Catalog $catalog, Product $product): Response
    {
        PublicCatalogAccess::ensureOpen($catalog);

        $product->load(['category', 'tags', 'media', 'specifications']);

        $detail = (new ProductDetailResource($product, $catalog, $this->paymentAccounts->all()))->resolve($request);
        $maxQuantity = max(1, (int) $detail['maxQuantity']);
        $quantity = $this->quantityFrom($request, $maxQuantity);

        return Inertia::render('catalog/LayawayCreate', [
            'catalog' => CatalogViewResource::forVisitor($catalog, $this->limits->plan(), $this->products)->resolve($request),
            'product' => $detail,
            // Lo único que pide la recarga parcial al cambiar la cantidad.
            'plan' => fn (): ?array => $this->plan($request, $product, $quantity),
            'quantity' => $quantity,
            'consent' => [
                'businessName' => $catalog->organization->name,
                'platformTermsDrafted' => LegalController::platformTermsAreDrafted(),
            ],
            // El censo de prefijos, como dato: el aviso que no bloquea
            // pregunta si el código tecleado está aquí (`PhoneNumber`).
            'recognisedAreaCodes' => PhoneNumber::recognisedAreaCodes(),
        ]);
    }

    /**
     * @throws ValidationException con el campo de cada rechazo
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

        return to_route('catalog.layaway.thanks', ['catalog' => $catalog->slug, 'code' => $layaway->code]);
    }

    public function thanks(Request $request, Catalog $catalog, string $code): Response
    {
        $confirmed = $request->session()->get(self::SESSION_KEY, []);

        if (! is_array($confirmed) || ! in_array($code, $confirmed, true)) {
            abort(404);
        }

        // Por el global scope: el código de otro negocio no existe aquí.
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
                // RB-10 · sin correo, esta pantalla es el único canal.
                'hasEmail' => $customer?->email !== null,
            ],
            'paymentAccounts' => array_map(fn ($account): array => $account->toArray(), $this->paymentAccounts->all()),
            // `T-059` · el formulario del comprobante vive en el portal. Sin
            // sesión, el acceso pide documento y código y vuelve aquí
            // (`redirect()->intended()`): crear un apartado no abre sesión.
            'receiptUrl' => route('portal.receipts.create', ['code' => $layaway->code]),
        ]);
    }

    /**
     * La cantidad del resumen: la pedida, acotada a lo que el formulario admite.
     */
    private function quantityFrom(Request $request, int $maxQuantity): int
    {
        $raw = $request->query('quantity');
        $quantity = is_numeric($raw) ? (int) $raw : 1;

        return max(1, min($quantity, $maxQuantity, CreateLayawayData::MAX_QUANTITY));
    }

    /**
     * El plan del apartado entero, o nada si el producto no tiene uno que se
     * pueda congelar (sin plan, la ficha ya lo dice y no se puede apartar).
     *
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

    /**
     * El plazo de la inicial, en la zona del negocio y como se lee.
     */
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
