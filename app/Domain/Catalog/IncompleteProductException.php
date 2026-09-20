<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Product;
use RuntimeException;

/**
 * El producto no reúne todavía lo que hace falta para publicarlo
 * (`REQ-BOF-020` RB-5, SP-3).
 *
 * ## Lleva la lista de lo que falta, y no un booleano
 *
 * Es lo que pide SP-3 —«`422` con la lista de lo que falta»— y la diferencia
 * no es de estilo. Quien pulsa «Publicar» con un producto sin foto y sin
 * categoría necesita saber **esas dos cosas**, porque va a arreglarlas de una
 * pasada; un «el producto está incompleto» lo manda a buscar, y un mensaje que
 * nombre solo el primer fallo lo manda a buscar dos veces.
 *
 * Por eso `$missing` es un mapa **campo → qué corregir**: la pantalla puede
 * poner cada frase junto a su control, y el resumen de `getMessage()` los
 * nombra todos de un vistazo. Las dos formas salen de la misma lista, así que
 * no pueden discrepar.
 *
 * ## Las cuatro condiciones se comprueban en un solo sitio
 *
 * Aquí solo viven el transporte y la redacción; quien las evalúa es
 * `ChangeProductStatusAction`, en la transición a `published`, y **nadie
 * más**. La razón está escrita en el plan bajo `T-029` y bajo `T-030`: con dos
 * listas de requisitos para publicar repartidas entre tareas, la que se queda
 * corta no falla, publica de menos o de más — y el síntoma aparece cuando un
 * cliente abre una ficha sin foto.
 *
 * ## Es una excepción y no un valor de retorno
 *
 * Por lo mismo que `CategoryInUseException`: un `false` se puede ignorar sin
 * escribir nada, y un llamante que lo ignorara enseñaría «producto publicado»
 * sobre un borrador. Aquí olvidarlo no es posible.
 *
 * `422`: la petición está bien formada y quien la hace tiene permiso. Lo que
 * falta son datos suyos, y el mensaje dice cuáles.
 */
final class IncompleteProductException extends RuntimeException
{
    /**
     * Código HTTP con el que se responde (SP-3).
     */
    public const STATUS = 422;

    /**
     * @param  array<string, string>  $missing  campo → qué hay que corregir
     */
    private function __construct(
        public readonly Product $product,
        public readonly array $missing,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, string>  $missing  campo → qué hay que corregir, no vacío
     */
    public static function of(Product $product, array $missing): self
    {
        return new self($product, $missing, self::messageFor($product, $missing));
    }

    /**
     * Lo que se le dice a quien pulsó «Publicar».
     *
     * Una lista y no una frase con concordancia: «le falta» y «le faltan»
     * obligarían a contar, y «las condiciones de pago» no encaja en ninguna de
     * las dos. Lo que importa es que se lean los tres o cuatro pendientes de
     * una vez.
     *
     * @param  array<string, string>  $missing
     */
    private static function messageFor(Product $product, array $missing): string
    {
        return sprintf(
            '«%s» todavía no se puede publicar. Pendiente: %s. Complétalo y vuelve a publicarlo.',
            $product->name,
            self::listOf(self::labelsFor($missing)),
        );
    }

    /**
     * Los rótulos de lo que falta, sin repetir.
     *
     * Las seis reglas de coherencia comparten rótulo a propósito: son un solo
     * pendiente —«las condiciones de pago»— y enumerarlas por separado en el
     * resumen daría una lista de cuatro entradas para un formulario que se
     * arregla mirando un bloque. El detalle de cada una sigue viajando en
     * `$missing`, junto a su campo.
     *
     * @param  array<string, string>  $missing
     * @return list<string>
     */
    private static function labelsFor(array $missing): array
    {
        $labels = [];

        foreach (array_keys($missing) as $field) {
            $labels[] = match ($field) {
                'images' => 'al menos una foto',
                'category_id' => 'una categoría',
                'description' => 'la descripción',
                default => 'las condiciones de pago',
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
