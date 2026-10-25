<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Actions\Customer\CustomerContactData;
use App\Domain\Customer\TermsConsent;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\Product;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CreateLayawayData
{
    public const MAX_QUANTITY = 10;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        public Catalog $catalog,
        public Product $product,
        public int $quantity,
        public CustomerContactData $contact,
        public TermsConsent $consent,
        public string $idempotencyKey,
        public ?Account $account = null,
    ) {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new InvalidArgumentException(sprintf(
                'Se aparta entre 1 y %d unidades, y se pidieron %d.',
                self::MAX_QUANTITY,
                $quantity,
            ));
        }

        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException(
                'La clave de idempotencia es el UUID que el formulario genera al montarse.'
            );
        }
    }
}
