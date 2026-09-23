<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Catalog;
use RuntimeException;

/**
 * El catálogo existió y su campaña terminó (`REQ-CAT-021` SP-3).
 *
 * ## `410` y no `404`, a propósito
 *
 * Quien llega aquí pulsó un enlace que alguien le mandó por WhatsApp, y un
 * `404` le diría que el enlace está mal —que lo copió mal, que se lo pasaron
 * roto—. No es verdad: el enlace era bueno y la campaña caducó. «Existió» no es
 * lo mismo que «no existe», y la página lo explica con esas palabras.
 *
 * El borrador **no** llega aquí aunque su `expires_at` ya haya pasado: lo que
 * nunca se publicó no caducó, y su enlace nunca circuló. Ese orden lo decide
 * `Catalog::visibility()`, que es quien sabe evaluarlo, y esta clase solo se
 * lanza cuando esa función ya dijo `Expired`.
 *
 * ## Lleva lo que la página necesita, y nada del backoffice
 *
 * El nombre de la campaña y el del negocio. Es una superficie pública: lo que
 * viaje aquí lo ve cualquiera que tenga el enlace, y eso descarta fechas
 * internas, identificadores y estado del plan.
 *
 * El enlace a la vitrina que pide SP-3 «si está activada» **no** está todavía:
 * la vitrina la construye `T-036`, y enlazar hoy a una página que no existe
 * sería mandar a quien ya llegó tarde a un segundo callejón.
 * `CatalogShowTest` lo marca con un caso que se pone rojo cuando la ruta
 * aparezca.
 */
final class CatalogExpiredException extends RuntimeException
{
    /**
     * Código HTTP con el que se responde (SP-3).
     */
    public const STATUS = 410;

    private function __construct(
        public readonly string $catalogName,
        public readonly string $businessName,
    ) {
        parent::__construct(sprintf(
            'La campaña «%s» de %s ya terminó.',
            $catalogName,
            $businessName,
        ));
    }

    /**
     * La campaña que terminó, con el negocio que la publicó.
     */
    public static function of(Catalog $catalog): self
    {
        return new self($catalog->name, $catalog->organization->name);
    }

    /**
     * Lo que recibe la página del `410`.
     *
     * @return array{catalogName: string, businessName: string}
     */
    public function toArray(): array
    {
        return [
            'catalogName' => $this->catalogName,
            'businessName' => $this->businessName,
        ];
    }
}
