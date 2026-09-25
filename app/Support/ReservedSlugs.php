<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Slugs que una organización no puede reclamar (`REQ-ORG-001` RB-2,
 * [05 §7.2]).
 *
 * La vitrina de un negocio vive en `/{organization:slug}`, un comodín de un
 * segmento que se registra **al final** de las rutas públicas para ceder ante
 * toda ruta literal. Esa cesión es justamente el problema: si alguien se
 * llamara «Precios», su vitrina no sería inalcanzable de forma ruidosa —sería
 * la página de precios de la plataforma, y el negocio vería su propia web
 * mostrando otra cosa sin ningún error en ningún log—.
 *
 * De ahí que la comprobación viva aquí y no en una regla de validación suelta:
 * la usan el registro y el renombrado (`REQ-ORG-006`), y una lista duplicada
 * se desincronizaría en la dirección peligrosa —la del formulario que todavía
 * la deja pasar—.
 */
final class ReservedSlugs
{
    /**
     * Los doce segmentos de la lista cerrada de [05 §7.2].
     *
     * @var list<string>
     */
    private const APPLICATION = [
        'registro',
        'precios',
        'backoffice',
        'plataforma',
        'c',
        'mi-cuenta',
        'api',
        'archivos',
        'terminos',
        'privacidad',
        'invitacion',
        'cuenta',
    ];

    /**
     * Segmentos que ocupa el framework y que no aparecen en la tabla de rutas.
     *
     * No son una ampliación de la lista de [05 §7.2] —esa es de rutas
     * nuestras— sino los dos caminos que Laravel registra por su cuenta: el
     * *health check* de `bootstrap/app.php` y el disco público. Si un negocio
     * se llamara «Up», su vitrina quedaría tapada por una respuesta de salud,
     * y el síntoma sería idéntico al de la lista de arriba.
     *
     * @var list<string>
     */
    private const FRAMEWORK = [
        'up',
        'storage',
    ];

    /**
     * Indica si el slug está reservado.
     *
     * Compara sobre el slug normalizado: `Precios`, `PRECIOS` y ` precios `
     * son el mismo segmento de URL.
     */
    public static function contains(string $slug): bool
    {
        return in_array(Str::slug($slug), self::all(), true);
    }

    /**
     * Todos los slugs reservados, para la validación y para los tests.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [...self::APPLICATION, ...self::FRAMEWORK];
    }

    /**
     * La restricción del parámetro `{organization}` de la vitrina
     * (`T-036`): un slug con forma de slug que **no** es ninguno de estos.
     *
     * La ruta de la vitrina es un comodín de un segmento en la raíz, y el
     * orden de los ficheros de rutas solo la protege mientras nadie los
     * reordene y mientras toda ruta reservada exista ya. Hoy no existen
     * `/precios`, `/terminos` ni `/c` a secas, así que sin esta restricción
     * esas URL caerían en la vitrina de nadie. Con ella, un segmento reservado
     * **no puede** llegar a la vitrina, esté donde esté la ruta que lo ocupe o
     * aunque todavía no exista. El mismo censo sirve al registro, al renombrado
     * y al router: una sola lista.
     */
    public static function routePattern(): string
    {
        $reserved = implode('|', array_map(fn (string $slug): string => preg_quote($slug, '#'), self::all()));

        return '(?!(?:'.$reserved.')$)[a-z0-9]+(?:-[a-z0-9]+)*';
    }
}
