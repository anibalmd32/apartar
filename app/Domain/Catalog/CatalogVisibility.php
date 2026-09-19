<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Qué se puede hacer hoy con un catálogo (`REQ-CAT-022`).
 *
 * **Es un estado derivado, no una columna.** Sale de `is_listed`,
 * `published_at` y `expires_at`, y `catalogs` no tiene —ni va a tener— una
 * columna `visibility`. Guardarlo sería escribir dos veces la misma lista: el
 * día en que la columna dijera `listed` y las fechas dijeran `expired`, el
 * catálogo seguiría vendiendo con los precios de una campaña terminada, y
 * nadie sabría cuál de los dos datos creer. Quien deriva es
 * `Catalog::visibility()`, que es también donde está escrito el **orden** en
 * que se evalúan los cuatro casos, porque se solapan.
 *
 * La expiración se evalúa así en la consulta y **sin job** (RB-6): un catálogo
 * caduca a su hora aunque la cola esté parada.
 *
 * ## Los dos métodos no preguntan lo mismo, y ahí está el matiz
 *
 * `is_listed` **no controla el acceso** (RB-3): controla si el catálogo
 * aparece en la vitrina del negocio. Un `Unlisted` es perfectamente accesible
 * por enlace —es el modo en que se reparte una campaña por WhatsApp—, así que
 * `isPubliclyAccessible()` cubre `Listed` **y** `Unlisted`, y solo
 * `appearsInStorefront()` se queda con el primero. Confundirlos convertiría
 * «no aparece en mi vitrina» en «es privado», que es lo contrario de lo que la
 * UI le promete al negocio.
 *
 * Los dos estados inaccesibles no se responden igual en HTTP y la diferencia
 * es deliberada: `Draft` da `404` y `Expired` da **`410 Gone`**
 * (`REQ-CAT-021` SP-2 y SP-3), porque «existió» no es lo mismo que «no
 * existe» para quien pulsó un enlace que alguien le mandó.
 */
enum CatalogVisibility: string
{
    /** `published_at` nulo: nunca se publicó, o se despublicó (RB-7). */
    case Draft = 'draft';

    /** Publicado y en la vitrina del negocio. */
    case Listed = 'listed';

    /** Publicado, fuera de la vitrina, accesible por enlace directo. */
    case Unlisted = 'unlisted';

    /** `expires_at` ya pasó: la campaña terminó. */
    case Expired = 'expired';

    /**
     * Indica si el catálogo se sirve a quien tenga su URL.
     *
     * Sin autenticación y sin contraseña (`REQ-CAT-021` RB-2). Es la
     * condición que sostiene toda la cara pública: de un catálogo accesible se
     * derivan la organización, su taxonomía, su tasa, sus cuentas de cobro y
     * su branding (RB-3).
     */
    public function isPubliclyAccessible(): bool
    {
        return match ($this) {
            self::Listed, self::Unlisted => true,
            self::Draft, self::Expired => false,
        };
    }

    /**
     * Indica si el catálogo se lista en la vitrina pública del negocio
     * (`REQ-ORG-006` RB-6).
     *
     * Solo `Listed`. Un `Unlisted` sigue siendo accesible por su enlace, y esa
     * asimetría es justamente la función de `is_listed`.
     */
    public function appearsInStorefront(): bool
    {
        return $this === self::Listed;
    }
}
