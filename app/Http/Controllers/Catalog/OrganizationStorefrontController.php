<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Tenancy\OrganizationBrand;
use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogViewResource;
use App\Models\Catalog;
use App\Models\Organization;
use App\Support\CatalogShareLink;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La vitrina del negocio: `apartar.store/{slug}`, la página que va en la bio
 * de Instagram y que lista sus catálogos (`REQ-ORG-006` RB-5, RB-6, SP-4).
 *
 * ## Cuando esto corre, la vitrina está encendida
 *
 * `ResolveStorefrontTenant` fijó la organización desde el slug, y solo la
 * encuentra si tiene `storefront_enabled`: la apagada y la que no existe ya
 * dieron el mismo `404` sin llegar aquí. Lo que queda es una vitrina
 * encendida, y una vitrina encendida **nunca** es `404`: sin catálogos que
 * enseñar es una página con estado vacío (SP-4), porque el negocio compartió
 * el enlace y un `404` le haría creer que está roto. Una organización
 * suspendida entra aquí sin rama propia: sus catálogos están despublicados
 * (`REQ-ORG-008` RB-2) y su vitrina sale vacía por el mismo camino.
 *
 * ## Qué catálogos, y quién lo decide
 *
 * Los de **esta** organización —el *global scope* ya está puesto— y de ellos
 * solo los que `CatalogVisibility::appearsInStorefront()` acepta: los
 * `Listed`. Sin una segunda condición escrita aquí: un `Unlisted` se comparte
 * por enlace y su dueño no quiere verlo en una lista, y `Draft` y `Expired`
 * no se enseñan en ningún sitio. Los catálogos de un negocio son pocos —los
 * limita el plan—, así que se leen todos y se filtra con esa función; sin
 * paginación que nadie necesita y sin `count(*)`.
 *
 * ## La marca, según el plan (RB-5, RB-5b)
 *
 * Igual que la vista del catálogo: `LimitEnforcer::plan()` se **lee**, y de
 * ese plan salen a la vez si se aplica la marca y si va el sello «Hecho con
 * Apartar». Nada aquí es una guarda.
 *
 * Ni telemetría ni índice de organizaciones: no existe una página que las
 * liste (RB-6).
 */
final class OrganizationStorefrontController extends Controller
{
    public function __construct(private readonly LimitEnforcer $limits) {}

    public function __invoke(Organization $organization): Response
    {
        $plan = $this->limits->plan();

        return Inertia::render('catalog/OrganizationStorefront', [
            'business' => OrganizationBrand::of($organization, $plan->hasFeature(PlanFeature::Branding))->toArray(),
            'showsPlatformBadge' => $plan->showsPlatformBadge(),
            'catalogs' => $this->listedCatalogs(),
        ]);
    }

    /**
     * Los catálogos que aparecen en la vitrina, lo último publicado primero.
     *
     * @return list<array{slug: string, name: string, description: string|null, cover: array{thumb: string, card: string, zoom: string, alt: string}|null, url: string, expiresAt: string|null}>
     */
    private function listedCatalogs(): array
    {
        return Catalog::query()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Catalog $catalog): bool => $catalog->visibility()->appearsInStorefront())
            ->map(fn (Catalog $catalog): array => [
                'slug' => $catalog->slug,
                'name' => $catalog->name,
                'description' => $catalog->description,
                'cover' => CatalogViewResource::ownCoverOf($catalog)?->toArray(),
                'url' => CatalogShareLink::of($catalog->slug),
                'expiresAt' => $catalog->expires_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
