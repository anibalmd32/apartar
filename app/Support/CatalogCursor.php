<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Catalog\CatalogSort;
use Illuminate\Pagination\Cursor;

/**
 * El cursor de la parrilla pública del catálogo (`REQ-CAT-021` RB-6, SP-7,
 * SP-9).
 *
 * ## Por cursor, y sin contar
 *
 * Doce por lote con «Cargar más», sin números de página (RB-6). Un paginador
 * por páginas necesita el total para pintar «1 2 3 … 42», y ese total es un
 * `count(*)` sobre el catálogo que **cada visita** paga para enseñar un número
 * que en móvil nadie mira. Con cursor, cada lote es «los doce siguientes a
 * este», que con el índice `(catalog_id, position)` es una lectura por rango
 * aunque el catálogo tenga quinientos productos (SP-7).
 *
 * ## Un cursor manipulado vuelve al principio, no rompe (SP-9)
 *
 * El cursor viaja en la petición y cualquiera puede editarlo. El de Laravel no
 * se defiende de eso: `Cursor::fromEncoded()` acepta cualquier JSON válido y
 * lo trata como si tuviera su forma, así que `?cursor=` con un número o con un
 * objeto sin `_pointsToNextItems` es un aviso de PHP —un `500`— y uno con
 * claves inventadas es una excepción al leerlo. Aquí se acepta **solo la forma
 * exacta que emite la parrilla para ese orden** (`CatalogSort::keyset()`):
 * sus columnas, en su orden, cada una con su tipo, y la dirección. Cualquier
 * otra cosa es la primera página, sin error y sin log, porque quien la recibe
 * no puede hacer nada con un mensaje.
 *
 * Eso incluye el cursor **de otro orden** (`T-034`): el de «precio» no tiene
 * las claves del curado, y se rechaza por forma, sin tener que recordar de qué
 * orden venía. Lo que la forma no puede distinguir es un cursor del mismo
 * orden emitido con **otros filtros**; ese no llega porque el cursor nunca
 * está en la URL y cambiar un filtro no lo envía (`useCatalogFilters`).
 *
 * ## La relevancia se pagina por desplazamiento
 *
 * Ver `CatalogSort`: su cursor es `{"offset": n}`, siempre hacia delante y con
 * techo, para que un `offset` de nueve cifras no sea una consulta cara pedida
 * a mano.
 */
final class CatalogCursor
{
    /**
     * Parámetro de la petición.
     */
    public const NAME = 'cursor';

    /**
     * Productos por lote (RB-6).
     */
    public const PER_PAGE = 12;

    /**
     * La clave del cursor por desplazamiento de `CatalogSort::Relevance`.
     */
    public const OFFSET = 'offset';

    /**
     * «Sin cursor», dicho de forma que el paginador no vaya a buscarlo.
     *
     * Si al paginador se le pasa `null`, **vuelve a leer `?cursor=` de la
     * petición** con `Cursor::fromEncoded()` —justo lo que esta clase existe
     * para no hacer—. La cadena vacía se decodifica a «ninguno» sin mirar la
     * petición: es la primera página, pedida explícitamente.
     */
    public const FIRST_PAGE = '';

    /**
     * Hasta dónde se deja avanzar la relevancia: cien lotes. Una búsqueda con
     * más de mil doscientas coincidencias dentro de un catálogo no se recorre
     * pulsando «Cargar más».
     */
    public const MAX_OFFSET = 100 * self::PER_PAGE;

    /**
     * Longitud máxima que se intenta decodificar.
     *
     * El cursor más largo legítimo es el del orden por nombre: doscientos
     * caracteres de nombre, que `json_encode` escribe como `á` si llevan
     * tilde, y en base64. Lo que pase de aquí no se decodifica ni se mira.
     */
    private const MAX_LENGTH = 2048;

    /**
     * El largo de `products.name`.
     */
    private const MAX_NAME_LENGTH = 200;

    /**
     * El cursor de la petición si tiene la forma de ese orden, o `null`.
     */
    public static function from(mixed $encoded, CatalogSort $sort): ?Cursor
    {
        if (! is_string($encoded) || $encoded === '' || strlen($encoded) > self::MAX_LENGTH) {
            return null;
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        $parameters = $decoded === false ? null : json_decode($decoded, true);

        if (! is_array($parameters)) {
            return null;
        }

        $keyset = $sort->keyset();

        return $keyset === null ? self::withOffset($parameters) : self::withShape($parameters, array_keys($keyset));
    }

    /**
     * El desplazamiento de un cursor ya validado de `CatalogSort::Relevance`.
     */
    public static function offsetOf(?Cursor $cursor): int
    {
        // `from()` ya comprobó que es un entero positivo con techo; el PHPDoc
        // de `Cursor::parameter()` dice `string`, y por eso el molde.
        return (int) ($cursor?->parameter(self::OFFSET) ?? 0);
    }

    /**
     * El cursor del lote siguiente de la relevancia.
     */
    public static function encodeOffset(int $offset): string
    {
        return (new Cursor([self::OFFSET => $offset]))->encode();
    }

    /**
     * Construye el cursor solo si trae exactamente las claves esperadas.
     *
     * @param  array<mixed>  $parameters
     * @param  list<string>  $columns
     */
    private static function withShape(array $parameters, array $columns): ?Cursor
    {
        $direction = $parameters['_pointsToNextItems'] ?? null;
        unset($parameters['_pointsToNextItems']);

        if (! is_bool($direction) || array_keys($parameters) !== $columns) {
            return null;
        }

        $values = [];

        foreach ($parameters as $column => $value) {
            $value = self::value((string) $column, $value);

            if ($value === null) {
                return null;
            }

            $values[$column] = $value;
        }

        return new Cursor($values, $direction);
    }

    /**
     * @param  array<mixed>  $parameters
     */
    private static function withOffset(array $parameters): ?Cursor
    {
        $offset = $parameters[self::OFFSET] ?? null;

        if (array_keys($parameters) !== [self::OFFSET, '_pointsToNextItems'] || $parameters['_pointsToNextItems'] !== true) {
            return null;
        }

        if (! is_int($offset) || $offset <= 0 || $offset > self::MAX_OFFSET) {
            return null;
        }

        return new Cursor([self::OFFSET => $offset]);
    }

    /**
     * El valor de una columna del cursor con su tipo, o `null` si no es un
     * valor que esta parrilla pudo emitir.
     */
    private static function value(string $column, mixed $value): int|string|null
    {
        return match ($column) {
            // La fecha en que entró al catálogo, como la devuelve MySQL.
            'catalog_added_at' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1
                ? $value
                : null,
            // El nombre se compara como parámetro enlazado; solo se le exige
            // que sea un texto que quepa en la columna.
            'products.name' => is_string($value) && $value !== '' && mb_strlen($value, 'UTF-8') <= self::MAX_NAME_LENGTH
                ? $value
                : null,
            // Posiciones, precios en centavos e identificadores: enteros no
            // negativos, a veces como texto porque así los devuelve la base.
            default => (is_int($value) && $value >= 0) || (is_string($value) && ctype_digit($value) && strlen($value) <= 18)
                ? (int) $value
                : null,
        };
    }
}
