<?php

declare(strict_types=1);

namespace App\Support;

/**
 * La URL pública de un catálogo, entera (`REQ-CAT-022` RB-1,
 * `REQ-BOF-024` RB-5).
 *
 * `apartar.store/c/{slug}`: el enlace que el negocio pega en WhatsApp. RB-5
 * pide **la URL completa con botón de copiar**, no el slug, y la diferencia es
 * la que hay entre copiar algo que funciona y copiar algo que hay que
 * completar de memoria.
 *
 * ## Se enruta desde `T-033`
 *
 * Hasta que existió la vista pública, el enlace se componía sobre `APP_URL`
 * porque `route()` no tenía a qué apuntar. Ahora sale de la ruta con nombre,
 * que es la misma que el backoffice ofrece para copiar y la que las etiquetas
 * Open Graph anuncian como canónica: si algún día cambiara la forma de la URL,
 * cambiaría en los tres sitios a la vez.
 *
 * El prefijo `/c/` no es arbitrario ni se puede mover: es uno de los doce
 * segmentos reservados de [05 §7.2] precisamente para que ninguna vitrina de
 * negocio pueda ocuparlo.
 */
final class CatalogShareLink
{
    /**
     * Segmento bajo el que vive toda la superficie pública de catálogo.
     */
    public const PREFIX = 'c';

    /**
     * Nombre de la ruta de la vista pública (`routes/catalog.php`).
     */
    public const ROUTE = 'catalog.show';

    /**
     * El enlace que se copia y se difunde.
     */
    public static function of(string $slug): string
    {
        return route(self::ROUTE, ['catalog' => $slug]);
    }
}
