<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * El enlace público de un catálogo, libre en **toda la plataforma**
 * (`REQ-CAT-022` RB-1, SP-7).
 *
 * Es la única regla de unicidad del proyecto que no se acota por organización,
 * y no por capricho: `catalogs.slug` es la URL `apartar.store/c/{slug}` que el
 * negocio pega en WhatsApp, y dos negocios no pueden compartirla. La columna
 * lleva su `UNIQUE(slug)` desde `T-024` ([05 §4.1]) justamente por eso.
 *
 * ## Y por eso no se puede preguntar «¿está libre?»
 *
 * La respuesta a esa pregunta vive en las filas del negocio de al lado, y
 * leerlas exigiría un `Catalog::withoutTenantScope()` — que solo está
 * permitido en `app/Queries/Platform` y `app/Actions/Platform`
 * (`REQ-ORG-002` RB-1). Sería la primera grieta en el aislamiento, y encima
 * abierta para mirar lo que un competidor tiene guardado.
 *
 * **No hace falta.** Lo que el alta necesita no es saber si un slug está libre
 * sino **acabar con uno libre**, y eso lo resuelve el índice: se intenta
 * escribir, la base rechaza el choque, y en la vuelta siguiente se desambigua
 * con sufijo —`ofertas-septiembre`, `ofertas-septiembre-2`, …— como manda
 * RB-1. Es el patrón que `TaxonomyByName` (`T-026`) ya usa para la carrera
 * entre dos formularios de producto; aquí resuelve además la colisión entre
 * organizaciones, que es la que no se puede ni mirar.
 *
 * El efecto secundario es el que RB-1 quiere: **nunca se rechaza por
 * duplicado**. Un negocio que llama a su campaña como la llamó otro se lleva
 * su variante y se entera al verla, no al recibir un `422` sobre un nombre que
 * es suyo y que nadie le ha dicho que estuviera tomado.
 *
 * ## Lo que no hace: reciclar
 *
 * Un catálogo despublicado **conserva su slug** (SP-7). Su fila sigue ahí y el
 * índice sigue ocupándolo, así que esta clase se lo salta sin tener que saber
 * nada: la URL circuló, y dársela a otro negocio mandaría a esa gente al sitio
 * equivocado en vez de a una página que dice que la campaña terminó.
 */
final class UniqueCatalogSlug
{
    /**
     * Tope de `catalogs.slug` ([05 §4.1]).
     */
    public const MAX_LENGTH = 100;

    /**
     * Cuántos sufijos se prueban antes de rendirse.
     *
     * Veinticinco, que es mucho más que las colisiones plausibles de un
     * nombre de campaña y mucho menos que un bucle sin fin. El tope existe
     * para que un choque de unicidad **que no sea el slug** —una columna nueva
     * con su propio índice— termine en el error que es en vez de repetirse
     * para siempre con nombres distintos.
     */
    private const ATTEMPTS = 25;

    /**
     * Escribe con el primer slug que el índice acepte.
     *
     * `$save` recibe el candidato y hace el `INSERT` o el `UPDATE`. Se le pasa
     * la escritura entera y no solo la comprobación porque **entre comprobar y
     * escribir cabe otra petición**: es la misma carrera que resuelve el alta
     * de organización, y la única forma de cerrarla es que quien decide sea el
     * índice.
     *
     * @template TReturn
     *
     * @param  string  $desired  el nombre del catálogo, o el enlace que se escribió a mano
     * @param  Closure(string): TReturn  $save  la escritura, que puede chocar con `UNIQUE(slug)`
     * @return TReturn
     *
     * @throws UniqueConstraintViolationException si el choque persiste y no es del slug
     */
    public function generate(string $desired, Closure $save): mixed
    {
        $base = self::base($desired);

        // Sin condición de salida en la cabecera, a propósito: el bucle
        // devuelve el resultado de la escritura o relanza el choque al agotar
        // los sufijos, así que no hay un tercer final que escribir debajo —y
        // un `return` inalcanzable ahí sería un camino que nadie prueba.
        for ($attempt = 1; ; $attempt++) {
            try {
                return $save(self::candidate($base, $attempt));
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::ATTEMPTS) {
                    // No es la carrera que esta clase resuelve: veinticinco
                    // nombres distintos no chocan todos con el mismo índice.
                    throw $exception;
                }
            }
        }
    }

    /**
     * El slug de la enésima vuelta: el base, y después el base con sufijo.
     *
     * `ofertas-septiembre`, `ofertas-septiembre-2`, `ofertas-septiembre-3`…
     * exactamente como lo escribe RB-1. El sufijo se recorta contra el tope de
     * la columna para que la vuelta número diez no produzca un slug de 102
     * caracteres que vuelva a fallar por otro motivo.
     */
    private static function candidate(string $base, int $attempt): string
    {
        if ($attempt === 1) {
            return $base;
        }

        $suffix = '-'.$attempt;

        return Str::limit($base, self::MAX_LENGTH - strlen($suffix), '').$suffix;
    }

    /**
     * El slug tal como saldría del nombre, antes de desambiguar.
     *
     * Vacío no puede quedar: un nombre escrito entero en emoji o en un
     * alfabeto que `Str::slug` no translitera dejaría la cadena en blanco, y
     * dos catálogos así chocarían entre ellos sin que nadie entendiera por
     * qué. El nombre sigue siendo el suyo; lo que se inventa es la URL.
     */
    private static function base(string $desired): string
    {
        $base = Str::slug($desired);

        return $base === '' ? 'catalogo' : Str::limit($base, self::MAX_LENGTH, '');
    }
}
