<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalog\FinancingPlan;
use App\Domain\Catalog\ImageSet;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Catalog;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Un producto en la parrilla de un catálogo: el contrato `ProductCard` del
 * módulo [11] (`REQ-CAT-021` RB-7).
 *
 * ## Precio, inicial y cuota, los tres en bimoneda
 *
 * Los tres importes salen por `ProjectsMoney`, que es el único sitio por el
 * que un importe cruza al cliente, y con él la tasa de **esta** respuesta
 * (`CurrentRate`): el sello de la página y cada cifra de cada card se
 * calcularon con la misma. Sin tasa, `ves` es `null` en los tres y la card
 * enseña solo dólares (`REQ-FX-005` RB-4).
 *
 * La cuota la reparte `FinancingPlan` con `Money::allocateEvenly()`, que es la
 * misma cuenta que enseña el formulario del backoffice y la que generará el
 * cronograma real (`T-041`). Una división escrita aquí sería la tercera
 * versión de la misma cifra, y el cliente vería en la card una cuota y en su
 * apartado otra por un céntimo.
 *
 * Se construye con `new` y no con `tryFrom()`: un producto publicado pasó las
 * seis reglas de `CoherentFinancingTerms` al guardarse **y** al publicarse
 * (`REQ-BOF-020` RB-5), así que unas condiciones incoherentes aquí son un
 * invariante roto, no un caso que tratar. Enseñar la card sin cuota lo
 * escondería.
 *
 * ## Lo que no viaja
 *
 * Ni la descripción (el contrato lo excluye: la card no es la ficha), ni el
 * SKU, ni el stock en cifra —solo si queda—, ni nada que identifique al
 * producto en la base. Es una superficie pública.
 *
 * @property-read Product $resource
 */
final class ProductCardResource extends JsonResource
{
    use ProjectsMoney;

    public function __construct(Product $product, private readonly Catalog $catalog)
    {
        parent::__construct($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;

        $plan = new FinancingPlan(
            $product->price_cents,
            $product->down_payment_cents,
            $product->installments_count,
            $product->frequency_days,
        );

        return [
            'slug' => $product->slug,
            'name' => $product->name,
            'price' => $this->money($product->price_cents),
            'compareAtPrice' => $product->compare_at_price_cents === null
                ? null
                : $this->money($product->compare_at_price_cents),
            'downPayment' => $this->money($plan->downPayment),
            'installmentAmount' => $this->money($plan->installmentAmount()),
            // Fuera del contrato de [11], y a propósito: el reparto deja el
            // residuo en la última cuota, y «4 cuotas de $62.50» sobre un plan
            // cuya última es $62.51 enseña una cuota que no se cobra. Es lo que
            // ya dice la previsualización del backoffice (`REQ-BOF-020` RB-7).
            'lastInstallmentAmount' => $plan->hasUnevenLastInstallment()
                ? $this->money($plan->lastInstallmentAmount())
                : null,
            'installmentsCount' => $plan->installmentsCount,
            'frequencyDays' => $plan->frequencyDays,
            'image' => $this->image($product),
            // Publicar exige categoría (`REQ-BOF-020` RB-5), así que en la
            // parrilla pública siempre la hay.
            'categoryName' => (string) $product->category?->name,
            'tags' => $product->tags->map(fn (Tag $tag): string => $tag->name)->values()->all(),
            // RB-7 · el badge «Agotado». Sale la pregunta, no la cifra: cuántas
            // unidades le quedan a un negocio no es asunto de quien mira.
            'inStock' => $product->stock > 0,
            'catalogSlug' => $this->catalog->slug,
        ];
    }

    /**
     * La primera foto en sus tres tamaños, de la relación ya cargada.
     *
     * @return array{thumb: string, card: string, zoom: string, alt: string}|null
     */
    private function image(Product $product): ?array
    {
        $media = $product->getFirstMedia(Product::IMAGES);

        return $media instanceof Media ? ImageSet::of($media)->toArray() : null;
    }
}
