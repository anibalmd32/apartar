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
 * ## Se compone y no se enruta, todavía
 *
 * `GET /c/{catalog:slug}` la registra `T-033`, que es quien escribe la vista
 * pública. Hasta entonces `route()` no tiene a qué apuntar, así que el enlace
 * se compone sobre `APP_URL` — que es la misma base que `route()` usará
 * después—. Vive en su propia clase justamente por eso: el día que la ruta
 * exista, el cambio es de una línea y no hay tres `.tsx` concatenando
 * cadenas.
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
     * El enlace que se copia y se difunde.
     */
    public static function of(string $slug): string
    {
        return url(self::PREFIX.'/'.$slug);
    }
}
