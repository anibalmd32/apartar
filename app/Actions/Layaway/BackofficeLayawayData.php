<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Actions\Customer\CustomerContactData;
use App\Actions\Payment\ManualPaymentData;
use App\Domain\Customer\TermsConsent;
use App\Domain\Layaway\DownPaymentWindow;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class BackofficeLayawayData
{
    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        public Product $product,
        public int $quantity,
        public ?Customer $customer,
        public ?CustomerContactData $contact,
        public ?TermsConsent $consent,
        public DownPaymentWindow $window,
        public string $idempotencyKey,
        public ?ManualPaymentData $payment = null,
    ) {
        if ($quantity < 1 || $quantity > CreateLayawayData::MAX_QUANTITY) {
            throw new InvalidArgumentException(sprintf(
                'Se aparta entre 1 y %d unidades, y se pidieron %d.',
                CreateLayawayData::MAX_QUANTITY,
                $quantity,
            ));
        }

        if (($customer === null) === ($contact === null)) {
            throw new InvalidArgumentException('Un apartado del backoffice es para una ficha existente o para un cliente nuevo, no para ambos ni para ninguno.');
        }

        if ($contact !== null && $consent === null) {
            throw new InvalidArgumentException('Un cliente nuevo necesita que el negocio declare que aceptó las condiciones del apartado.');
        }

        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException(
                'La clave de idempotencia es el UUID que el formulario genera al montarse.'
            );
        }

        if ($payment !== null && $payment->idempotencyKey !== $idempotencyKey) {
            throw new InvalidArgumentException('El pago registrado al crear el apartado comparte su clave de idempotencia.');
        }
    }
}
