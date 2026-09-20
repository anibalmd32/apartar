<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Product;
use RuntimeException;

/**
 * Se intentó apartar un producto que ya no admite apartados
 * (`REQ-CAT-007` SP-2).
 *
 * ## El caso es el formulario que ya estaba abierto
 *
 * Nadie llega aquí pulsando por donde debe: el catálogo no pinta un producto
 * archivado y la ficha no ofrece el botón. Se llega **con el formulario
 * abierto desde antes**, que es el hueco que SP-2 describe — el negocio
 * archiva el producto mientras un cliente tenía la ficha en pantalla, y el
 * envío llega después. Por eso la comprobación no puede vivir en la pantalla:
 * la pantalla se pintó cuando la respuesta todavía era «sí».
 *
 * `422` y no `404`: el producto existe y el cliente lo estaba viendo hace un
 * minuto. Contestarle que no existe sería mentirle sobre lo que acaba de ver.
 * Y el mensaje no dice por qué se retiró: eso es del negocio, no del cliente.
 *
 * ## Qué pregunta y qué no
 *
 * Mira **el estado y nada más**, que es lo que contesta
 * `ProductStatus::canBeReserved()`. Las existencias son otra puerta y tienen
 * la suya (`REQ-LAY-003` SP-1, `REQ-CAT-007` RB-4): un producto agotado sigue
 * `published` y sigue en el catálogo con su sello, así que rechazarlo aquí
 * confundiría «se acabó» con «ya no se vende».
 *
 * ## Su llamante es de `T-044`
 *
 * `CreateLayawayAction` no existe todavía —los apartados son H3— y `T-030`
 * escribe el mecanismo, no el recorrido: `guard()` es la línea que esa acción
 * llamará, y está aquí en vez de dentro de ella para que la pregunta se
 * escriba una sola vez el día que haya dos sitios que apartan (el formulario
 * público y el alta manual del backoffice). Lo que `T-030` **no** hace es
 * inventar un recorrido de apartado para tener dónde llamarla.
 */
final class ProductNotAvailableException extends RuntimeException
{
    /**
     * Código HTTP con el que se responde (SP-2).
     */
    public const STATUS = 422;

    private function __construct(
        public readonly Product $product,
        public readonly ProductStatus $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * Deja pasar solo lo que su estado permite apartar (SP-2).
     *
     * @throws self si el producto es borrador o está archivado
     */
    public static function guard(Product $product): void
    {
        if ($product->status->canBeReserved()) {
            return;
        }

        throw self::for($product);
    }

    /**
     * El producto, tal como se lo explicamos a quien lo intentaba apartar.
     *
     * Una sola frase para los dos estados que no admiten apartado. Distinguir
     * «está en borrador» de «se archivó» le contaría al cliente en qué punto
     * de su inventario anda el negocio, y desde su lado son lo mismo: lo que
     * tenía delante ya no está a la venta.
     */
    public static function for(Product $product): self
    {
        return new self(
            $product,
            $product->status,
            sprintf(
                '«%s» ya no está disponible. El negocio lo retiró mientras tenías la página abierta; '
                .'vuelve al catálogo para ver lo que sí está.',
                $product->name,
            ),
        );
    }
}
