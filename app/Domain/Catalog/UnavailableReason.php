<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Product;

/**
 * Por qué no se puede apartar un producto que sí se ve (`REQ-CAT-003` RB-7,
 * SP-7).
 *
 * El botón «Apartar» se deshabilita **con un motivo escrito**, no en gris sin
 * más: un botón apagado sin explicación se lee como una página rota, y quien
 * está a punto de pagar necesita saber si es el producto, las unidades o el
 * negocio.
 *
 * ## El tercer caso es el del negocio, no el del producto
 *
 * `BusinessUnavailable` es el límite de plan de `REQ-SUB-004` RB-4: el negocio
 * agotó sus apartados activos, o su suscripción o su organización no admiten
 * crear. No lo decide este enum: lo pregunta quien pinta la ficha a
 * `LimitEnforcer::acceptsLayaways()` y se lo pasa a `of()` (`T-067`), y va
 * **primero** porque vale para todos los productos del negocio. Lo que lo
 * hace cumplir sigue siendo `assertCanCreate()` dentro de `CreateLayawayAction`:
 * esto solo evita ofrecer un formulario que se va a rechazar.
 *
 * Su mensaje es **neutro**: no nombra el plan, ni el límite, ni que el negocio
 * tenga que pagar nada. El plan de un negocio es asunto del negocio, y quien
 * mira el producto solo necesita saber que ahora no se puede y a quién
 * preguntar (`REQ-SUB-004` RB-4).
 */
enum UnavailableReason: string
{
    /** Las condiciones de pago no permiten repartir nada (SP-7). */
    case NotReservable = 'not_reservable';

    /** Publicado y sin unidades: se ve, no se aparta (`REQ-CAT-007` RB-4). */
    case OutOfStock = 'out_of_stock';

    /** El negocio no puede recibir apartados ahora. Lo alcanza `T-067`. */
    case BusinessUnavailable = 'business_unavailable';

    /**
     * El motivo que aplica hoy, o `null` si se puede apartar.
     *
     * En este orden: primero lo que ninguna reposición arregla —unas
     * condiciones que no dan un plan, o un estado que no admite apartados—, y
     * después las unidades. El producto llega ya visible: `draft`, archivado y
     * programado no tienen ficha (SP-2).
     */
    /**
     * @param  bool  $businessAccepts  si el negocio puede recibir hoy un apartado más (`LimitEnforcer::acceptsLayaways()`)
     */
    public static function of(Product $product, ?FinancingPlan $plan, bool $businessAccepts = true): ?self
    {
        // `REQ-SUB-004` RB-4 · el primero: vale para **todos** los productos
        // del negocio, y es lo que de verdad impide apartar. Con su mensaje
        // neutro, que no dice ni plan ni límite (`T-067`).
        if (! $businessAccepts) {
            return self::BusinessUnavailable;
        }

        if ($plan === null || ! $product->status->canBeReserved()) {
            return self::NotReservable;
        }

        if ($product->stock <= 0) {
            return self::OutOfStock;
        }

        return null;
    }

    /**
     * Lo que se le dice a quien mira el producto.
     */
    public function message(): string
    {
        return match ($this) {
            self::NotReservable => 'Este producto no se puede apartar. Escríbele al negocio si te interesa.',
            self::OutOfStock => 'Agotado: ahora mismo no quedan unidades para apartar.',
            self::BusinessUnavailable => 'Este negocio no está recibiendo apartados en este momento. Escríbele para consultar.',
        };
    }
}
