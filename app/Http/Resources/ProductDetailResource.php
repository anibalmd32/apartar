<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalog\FinancingPlan;
use App\Domain\Catalog\ImageSet;
use App\Domain\Catalog\SpecificationGroups;
use App\Domain\Catalog\UnavailableReason;
use App\Domain\Payment\PaymentAccountView;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Catalog;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * El producto tal como se decide apartarlo: el contrato `ProductDetail` del
 * módulo [11] (`REQ-CAT-003`).
 *
 * `ProductDetail` **extiende `ProductCard`**, y los campos que comparten se
 * calculan exactamente igual que en `ProductCardResource`: los importes por
 * `ProjectsMoney` —con la tasa de **esta** respuesta, la misma del sello— y la
 * cuota por `FinancingPlan`. Es la regla de `T-027`: una sola cuenta, llamada
 * desde la previsualización del backoffice, desde cada card y desde aquí. Una
 * división escrita en este archivo haría que la card dijera una cuota y la
 * ficha otra por un céntimo. `ProductShowTest` compara las dos, campo a campo.
 *
 * ## Al cliente no le llega nada con lo que dividir
 *
 * Viaja cada importe ya proyectado en las dos monedas (`MoneyView`), y el
 * *toggle* de moneda elige cuál va delante sin pedir nada: las dos están ya.
 * No viaja el financiado, ni los centavos de la inicial para restarlos, ni
 * nada que invite al `.tsx` a repartir por su cuenta.
 *
 * ## Lo que se añade a la card
 *
 *  - la descripción y el SKU, que la card no lleva;
 *  - **todas** las fotos en sus tres tamaños (`ImageSet`), en el orden del
 *    negocio; sin ninguna, lista vacía y la página pinta la inicial (SP-4);
 *  - la ficha técnica agrupada por `SpecificationGroups` —sin filas, lista
 *    vacía y la sección no se pinta (SP-5)—;
 *  - las **cuentas de cobro activas** (RB-5): dónde pagar, antes de
 *    comprometerse. Las inactivas no viajan: aquí no hay un dueño que tenga
 *    que poder reactivarlas;
 *  - `maxQuantity`, `min(stock, 10)` (RB-10), para que el formulario de
 *    `T-045` y su validación partan del mismo tope;
 *  - `canBeReserved` y `unavailableReason` (RB-7, `UnavailableReason`).
 *
 * ## Y lo que no, a propósito
 *
 * **`business`**, que el contrato de [11] pone dentro de `ProductDetail`:
 * la identidad del negocio es la del catálogo y ya viaja en
 * `catalog.business` (`CatalogView`), que es de donde la lee el layout. Dos
 * copias del mismo dato son dos sitios donde puede discrepar; la nota bajo
 * `REQ-CAT-003` lo deja escrito.
 *
 * ## SP-7, sin romper la página
 *
 * La card construye el plan con `new` porque un producto publicado ya pasó
 * `CoherentFinancingTerms`. La ficha usa `tryFrom()`: es la página que
 * **explica** por qué no se puede apartar, y unas condiciones incoherentes que
 * se colaran por otra puerta tienen que verse como «no se puede apartar», no
 * como un `500`. Sin plan no hay cuota que enseñar, y `installmentAmount` va
 * `null`.
 *
 * @property-read Product $resource
 */
final class ProductDetailResource extends JsonResource
{
    use ProjectsMoney;

    /**
     * Tope de unidades por apartado (RB-10).
     */
    public const MAX_QUANTITY = 10;

    /**
     * @param  list<PaymentAccountView>  $paymentAccounts  las activas del negocio, en su orden
     */
    public function __construct(
        Product $product,
        private readonly Catalog $catalog,
        private readonly array $paymentAccounts,
        // `REQ-SUB-004` RB-4 · `LimitEnforcer::acceptsLayaways()`.
        private readonly bool $businessAccepts = true,
    ) {
        parent::__construct($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;
        $plan = FinancingPlan::tryFrom(
            $product->price_cents,
            $product->down_payment_cents,
            $product->installments_count,
            $product->frequency_days,
        );
        $reason = UnavailableReason::of($product, $plan, $this->businessAccepts);

        return [
            'slug' => $product->slug,
            'name' => $product->name,
            'price' => $this->money($product->price_cents),
            'compareAtPrice' => $product->compare_at_price_cents === null ? null : $this->money($product->compare_at_price_cents),
            'downPayment' => $this->money($product->down_payment_cents),
            'installmentAmount' => $plan === null ? null : $this->money($plan->installmentAmount()),
            'lastInstallmentAmount' => $plan !== null && $plan->hasUnevenLastInstallment() ? $this->money($plan->lastInstallmentAmount()) : null,
            'installmentsCount' => $product->installments_count,
            'frequencyDays' => $product->frequency_days,
            'categoryName' => (string) $product->category?->name,
            'tags' => $product->tags->map(fn (Tag $tag): string => $tag->name)->values()->all(),
            'inStock' => $product->stock > 0,
            'catalogSlug' => $this->catalog->slug,
            'description' => (string) $product->description,
            'sku' => $product->sku,
            'images' => $product->getMedia(Product::IMAGES)
                ->map(fn (Media $media): array => ImageSet::of($media)->toArray())
                ->values()
                ->all(),
            'specificationGroups' => SpecificationGroups::of($product->specifications),
            'maxQuantity' => max(0, min($product->stock, self::MAX_QUANTITY)),
            'paymentAccounts' => array_map(fn (PaymentAccountView $account): array => $account->toArray(), $this->paymentAccounts),
            'canBeReserved' => $reason === null,
            'unavailableReason' => $reason?->message(),
        ];
    }
}
