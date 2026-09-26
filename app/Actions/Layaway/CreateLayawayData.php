<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Actions\Customer\CustomerContactData;
use App\Domain\Customer\TermsConsent;
use App\Models\Catalog;
use App\Models\Product;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Lo que llega del formulario de apartado, ya convertido en tipos
 * (`REQ-LAY-001`).
 *
 * ## No hay organización aquí, y es la regla
 *
 * RB-1: el apartado nace **dentro de un catálogo** y de él sale la
 * organización. Un `organization_id` en este objeto sería un apartado que se
 * puede crear en el negocio del vecino cambiando un número del formulario, así
 * que no existe el campo: `CreateLayawayAction` lo deriva de `$catalog`.
 *
 * ## Dos diferencias con el contrato de [20], y su motivo
 *
 *  - **`TermsConsent $consent` en lugar de `bool $termsAccepted`.** `T-043`
 *    decidió que la evidencia —instante e IP— viaja como un objeto que no se
 *    puede construir sin ella, y un booleano no es evidencia: diría «aceptó»
 *    sin decir cuándo ni desde dónde. El `FormRequest` de `T-045b` valida
 *    `accepted` y construye esto con `TermsConsent::acceptedNowFrom()`.
 *  - **Sin `?Account $account`**, porque `Account` es de H6. El enganche está
 *    nombrado en `CreateLayawayAction` y tiene su caso en rojo diferido.
 *
 * ## Lo que sí se comprueba al construir
 *
 * La cantidad entre 1 y 10 —el tope fijo de RB-5; el otro, `stock`, solo es
 * cierto bajo el bloqueo y lo comprueba la acción— y que la clave sea un UUID,
 * que es lo que genera el formulario al montarse (RB-12).
 */
final readonly class CreateLayawayData
{
    /** Tope de unidades por apartado, además del stock (`REQ-LAY-001` RB-5). */
    public const MAX_QUANTITY = 10;

    /**
     * @throws InvalidArgumentException si la cantidad o la clave no son válidas
     */
    public function __construct(
        public Catalog $catalog,
        public Product $product,
        public int $quantity,
        public CustomerContactData $contact,
        public TermsConsent $consent,
        public string $idempotencyKey,
    ) {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new InvalidArgumentException(sprintf(
                'Se aparta entre 1 y %d unidades, y se pidieron %d (REQ-LAY-001 RB-5).',
                self::MAX_QUANTITY,
                $quantity,
            ));
        }

        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException(
                'La clave de idempotencia es el UUID que el formulario genera al montarse (REQ-LAY-001 RB-12).'
            );
        }
    }
}
