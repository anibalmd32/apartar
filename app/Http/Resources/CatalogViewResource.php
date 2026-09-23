<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Catalog\UpsertCatalogAction;
use App\Domain\Catalog\ImageSet;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Tenancy\OrganizationBrand;
use App\Models\Catalog;
use App\Models\Plan;
use App\Support\CatalogShareLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * El catálogo tal como lo ve quien abre su enlace: el contrato `CatalogView`
 * del módulo [12] (`REQ-CAT-021`).
 *
 * ## La marca y el sello salen del mismo plan
 *
 * `showsPlatformBadge` es `Plan::showsPlatformBadge()` y `brandingEnabled` es
 * `hasFeature(Branding)`: las dos caras de una sola función (`REQ-CAT-022`
 * RB-5), leídas del **mismo** plan para que no puedan discrepar. Un catálogo
 * con el logo del negocio aplicado **y** el sello de Free sería un estado que
 * la spec no admite, y con dos lecturas separadas bastaría un cambio de plan
 * entre las dos para servirlo.
 *
 * ## Dos campos del contrato que no están, y por qué
 *
 *  - **`productsCount`**: es un `count(*)` sobre el catálogo por visita, justo
 *    el que RB-6 y SP-7 prohíben pagar para enseñar un número que nadie mira.
 *    La página no lo necesita: el estado vacío de SP-4 lo decide el primer
 *    lote.
 *  - **`subscription`**: es el estado del CTA «Seguir este catálogo» (RB-8),
 *    que necesita cuentas de consumidor y `catalog_subscriptions`. Llega con
 *    `T-073` y `T-075`; hasta entonces no hay sesión de cuenta que reflejar y
 *    un `null` fijo sería un campo que finge.
 *
 * ## Es una superficie pública
 *
 * Todo lo que sale de aquí lo ve cualquiera con el enlace. Ni el `id` del
 * catálogo ni el de la organización, ni fechas internas, ni nada del
 * backoffice: el slug es el único identificador, y ya estaba en la URL.
 *
 * @property-read Catalog $resource
 */
final class CatalogViewResource extends JsonResource
{
    /**
     * @param  ImageSet|null  $fallbackCover  la foto del primer producto, para cuando no hay portada propia
     */
    public function __construct(
        Catalog $catalog,
        private readonly Plan $plan,
        private readonly ?ImageSet $fallbackCover,
    ) {
        parent::__construct($catalog);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $catalog = $this->resource;

        return [
            'slug' => $catalog->slug,
            'name' => $catalog->name,
            'description' => $catalog->description,
            'cover' => $this->cover($catalog)?->toArray(),
            'business' => OrganizationBrand::of(
                $catalog->organization,
                $this->plan->hasFeature(PlanFeature::Branding),
            )->toArray(),
            'shareUrl' => CatalogShareLink::of($catalog->slug),
            'expiresAt' => $catalog->expires_at?->toIso8601String(),
            'showsPlatformBadge' => $this->plan->showsPlatformBadge(),
        ];
    }

    /**
     * La portada propia o, si no hay, la foto del primer producto
     * (`REQ-BOF-024` SP-9).
     *
     * La portada es un archivo suelto y no un medio con conversiones, así que
     * sus tres tamaños son la misma URL: es exactamente lo que `ImageSet`
     * promete cuando una conversión no existe (`REQ-CAT-005` RB-3), y le
     * ahorra a la página preguntar de qué clase de imagen se trata.
     */
    private function cover(Catalog $catalog): ?ImageSet
    {
        $path = $catalog->cover_path;

        if ($path === null || $path === '') {
            return $this->fallbackCover;
        }

        $url = Storage::disk(UpsertCatalogAction::COVER_DISK)->url($path);

        return new ImageSet($url, $url, $url, $catalog->name);
    }
}
