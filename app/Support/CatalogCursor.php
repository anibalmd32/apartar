<?php

declare(strict_types=1);

namespace App\Support;

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
 * El cursor viaja en la URL y cualquiera puede editarlo. El de Laravel no se
 * defiende de eso: `Cursor::fromEncoded()` acepta cualquier JSON válido y lo
 * trata como si tuviera su forma, así que `?cursor=` con un número o con un
 * objeto sin `_pointsToNextItems` es un aviso de PHP —un `500`— y uno con
 * claves inventadas es una excepción al leerlo. Aquí se acepta **solo la forma
 * exacta que emite esta parrilla**: las dos columnas del orden curado, enteras,
 * y la dirección. Cualquier otra cosa es la primera página, sin error y sin
 * log, porque quien la recibe no puede hacer nada con un mensaje.
 */
final class CatalogCursor
{
    /**
     * Parámetro de la query string.
     */
    public const NAME = 'cursor';

    /**
     * Productos por lote (RB-6).
     */
    public const PER_PAGE = 12;

    /**
     * Las columnas del orden curado, en el orden en que se aplican.
     *
     * `position` es el orden que eligió el negocio (`REQ-CAT-023`); el `id`
     * desempata y hace el orden **total**, que es lo que un cursor necesita
     * para no saltarse ni repetir filas entre lotes. `SyncCatalogProductsAction`
     * mantiene las posiciones contiguas y sin repetir, pero ninguna restricción
     * de la base lo garantiza, y un cursor que dependa de algo que nadie
     * garantiza pierde productos el día que falla.
     *
     * @var list<string>
     */
    public const COLUMNS = ['catalog_product.position', 'products.id'];

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
     * Longitud máxima que se intenta decodificar.
     *
     * Un cursor legítimo son dos enteros y un booleano, menos de cien
     * caracteres. Lo que pase de aquí no se decodifica ni se mira.
     */
    private const MAX_LENGTH = 256;

    /**
     * El cursor de la petición si tiene la forma de esta parrilla, o `null`.
     */
    public static function from(mixed $encoded): ?Cursor
    {
        if (! is_string($encoded) || $encoded === '' || strlen($encoded) > self::MAX_LENGTH) {
            return null;
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        $parameters = $decoded === false ? null : json_decode($decoded, true);

        if (! is_array($parameters)) {
            return null;
        }

        return self::withShape($parameters);
    }

    /**
     * Construye el cursor solo si trae exactamente las claves esperadas.
     *
     * @param  array<mixed>  $parameters
     */
    private static function withShape(array $parameters): ?Cursor
    {
        $direction = $parameters['_pointsToNextItems'] ?? null;
        unset($parameters['_pointsToNextItems']);

        if (! is_bool($direction) || array_keys($parameters) !== self::COLUMNS) {
            return null;
        }

        $values = [];

        foreach ($parameters as $column => $value) {
            // Posiciones e identificadores son enteros no negativos; lo que no
            // lo sea no lo emitió esta parrilla.
            if (! (is_int($value) && $value >= 0) && ! (is_string($value) && ctype_digit($value))) {
                return null;
            }

            $values[$column] = (int) $value;
        }

        return new Cursor($values, $direction);
    }
}
