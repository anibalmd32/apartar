<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Catalog;
use RuntimeException;

/**
 * El catálogo todavía no reúne lo que hace falta para publicarlo
 * (`REQ-CAT-022` RB-4, SP-1, SP-2, SP-4, SP-5, SP-8).
 *
 * ## Lleva la lista de lo que falta, como su gemela del producto
 *
 * Misma forma que `IncompleteProductException` y por el mismo motivo: quien
 * pulsa «Publicar» con un catálogo vacío **y** sin cuenta de cobro va a
 * arreglar las dos cosas de una pasada, y un mensaje que nombre solo la
 * primera lo manda a buscar dos veces. `$missing` es un mapa **campo → qué
 * corregir**, así que cada frase aterriza junto a su control y el resumen de
 * `getMessage()` las nombra todas de un vistazo; las dos formas salen de la
 * misma lista y no pueden discrepar.
 *
 * ## Y es una excepción, no un booleano
 *
 * Por lo mismo que `CategoryInUseException` y que `IncompleteProductException`:
 * un valor de retorno se ignora sin escribir nada, y un llamante que lo
 * ignorara enseñaría «catálogo publicado» sobre un borrador cuyo enlace da
 * `404`. Aquí olvidarlo no es posible.
 *
 * `422`: la petición está bien formada y quien la hace tiene permiso. Lo que
 * falta son datos suyos, y el mensaje dice cuáles. La suspensión **no** entra
 * aquí —es `403` y es `CreationSuspendedException`— porque no falta un dato:
 * falta estar al día (`REQ-CAT-022` SP-9).
 */
final class CatalogNotPublishableException extends RuntimeException
{
    /**
     * Código HTTP con el que se responde (SP-1, SP-2, SP-4, SP-5, SP-8).
     */
    public const STATUS = 422;

    /**
     * @param  array<string, string>  $missing  campo → qué hay que corregir
     */
    private function __construct(
        public readonly Catalog $catalog,
        public readonly array $missing,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, string>  $missing  campo → qué hay que corregir, no vacío
     */
    public static function of(Catalog $catalog, array $missing): self
    {
        return new self($catalog, $missing, self::messageFor($catalog, $missing));
    }

    /**
     * Lo que se le dice a quien pulsó «Publicar».
     *
     * @param  array<string, string>  $missing
     */
    private static function messageFor(Catalog $catalog, array $missing): string
    {
        return sprintf(
            '«%s» todavía no se puede publicar. Pendiente: %s.',
            $catalog->name,
            self::listOf(self::labelsFor($missing)),
        );
    }

    /**
     * Los rótulos de lo que falta, sin repetir.
     *
     * @param  array<string, string>  $missing
     * @return list<string>
     */
    private static function labelsFor(array $missing): array
    {
        $labels = [];

        foreach (array_keys($missing) as $field) {
            $labels[] = match ($field) {
                'products' => 'al menos un producto publicado dentro',
                'payment_accounts' => 'una cuenta de cobro activa',
                'expires_at' => 'una fecha de caducidad que no haya pasado',
                default => 'verificar el correo del dueño',
            };
        }

        return array_values(array_unique($labels));
    }

    /**
     * «a», «a y b», «a, b y c».
     *
     * @param  list<string>  $items
     */
    private static function listOf(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' y '.$last;
    }
}
