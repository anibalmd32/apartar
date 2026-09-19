<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Str;

/**
 * El `slug` de una fila del inventario, derivado de su nombre y libre dentro
 * de la organización (`REQ-BOF-023` RB-3, `REQ-BOF-020` RB-3).
 *
 * Vive aparte porque lo necesitan cuatro sitios —el alta de categoría, la de
 * etiqueta, la reutilización al vuelo de RB-8 y el alta de producto— y porque
 * la parte que se olvida no es «convertir el nombre en slug», que hace
 * `Str::slug`, sino **qué hacer cuando el resultado ya está tomado**.
 *
 * Las tres tablas comparten la regla —`UNIQUE(organization_id, slug)`— y la
 * condición bajo la que el enlace deja de ser editable es la única que cambia,
 * así que esa se queda en cada acción: en la categoría es «sin productos
 * publicados» (`REQ-BOF-023` SP-3) y en el producto es «no publicado»
 * (`REQ-BOF-020` SP-5). Lo que se comparte es generar, no decidir.
 *
 * ## Desambigua con sufijo en vez de rechazar
 *
 * `name` es único por organización, así que dos categorías nunca se llaman
 * igual — pero dos nombres **distintos** producen el mismo slug con
 * facilidad: «Ropa niño» y «Ropa-niño» dan los dos `ropa-nino`, y «Café» y
 * «Cafe» también. Rechazar el segundo obligaría al negocio a renombrar una
 * categoría legítima por un detalle del que nadie le ha hablado nunca. Es el
 * mismo criterio que `REQ-CAT-022` RB-1 aplica al slug del catálogo: se
 * sugiere `ropa-nino-2` en lugar de decir que no.
 *
 * ## Y no recicla: quien pregunta es quien escribe
 *
 * La comprobación llega como *closure* y no como una consulta escrita aquí,
 * porque las tablas son dos y la organización la pone el *global scope* de
 * cada modelo. Un `where('organization_id', …)` en este archivo daría el mismo
 * resultado hoy y sería exactamente la línea que `tenancy:audit` existe para
 * que nadie escriba.
 */
final class TaxonomySlug
{
    /**
     * Slug para ese nombre, saltando los que ya estén ocupados.
     *
     * @param  string  $name  el nombre tal como lo escribió el negocio
     * @param  Closure(string): bool  $isTaken  si ese slug ya existe en la organización
     * @param  int  $maxLength  tope de la columna: 120 en `categories`, 80 en `tags`, 200 en `products`
     */
    public static function from(string $name, Closure $isTaken, int $maxLength = 120): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            // Un nombre escrito entero en un alfabeto que `Str::slug` no
            // translitera —o a base de emoji— deja la cadena vacía, y un slug
            // vacío colisionaría con el siguiente igual de vacío. El nombre
            // sigue siendo el suyo; lo que se inventa es la URL, y el sufijo
            // del bucle de abajo la hace única.
            $base = 'sin-nombre';
        }

        $base = Str::limit($base, $maxLength - 4, '');
        $slug = $base;
        $suffix = 2;

        while ($isTaken($slug)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * El slug que escribió una persona, normalizado igual que el automático.
     *
     * Editarlo es una acción explícita (RB-6) y lo que se guarda tiene que
     * seguir siendo una URL: quien escribe «Ropa Niño» en ese campo está
     * pidiendo `ropa-nino`, no una cadena con espacios y mayúsculas dentro de
     * `/c/{catalog}?category=…`.
     */
    public static function normalize(string $slug, int $maxLength = 120): string
    {
        return Str::limit(Str::slug($slug), $maxLength, '');
    }
}
