<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

/**
 * Superficie desde la que se resuelve el tenant (`REQ-ORG-002` RB-4).
 *
 * La tabla de RB-4 declara de dónde sale la organización en cada superficie y
 * dice que no hay ambigüedad. Este enum es esa tabla en forma ejecutable: sin
 * él, "de dónde sale el tenant aquí" quedaría repartido entre tres middlewares
 * y sería imposible comprobar que están los cuatro casos.
 */
enum TenantSurface: string
{
    /** `/backoffice/*` — de la sesión del usuario del equipo. */
    case Backoffice = 'backoffice';

    /** `/c/{slug}` — del catálogo público de la URL. */
    case PublicCatalog = 'public_catalog';

    /** `/{org-slug}` — de la vitrina de la organización. */
    case Storefront = 'storefront';

    /** `/mi-cuenta/*` — de la ficha de cliente en contexto. */
    case Portal = 'portal';

    /** `/plataforma/*` — **ninguno**: opera sin tenant. */
    case Platform = 'platform';

    /**
     * Indica si la superficie opera con una organización fijada.
     *
     * El backoffice de plataforma es la única que no: ve a todas las
     * organizaciones y por eso consulta con `withoutTenantScope()`.
     */
    public function requiresTenant(): bool
    {
        return $this !== self::Platform;
    }
}
