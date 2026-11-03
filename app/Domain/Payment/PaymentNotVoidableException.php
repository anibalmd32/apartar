<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Layaway\LayawayStatus;
use RuntimeException;

final class PaymentNotVoidableException extends RuntimeException
{
    public static function layawayClosed(LayawayStatus $status): self
    {
        return new self(sprintf('El apartado está «%s»: sus pagos ya no se anulan.', mb_strtolower($status->label())));
    }

    public static function alreadyVoided(): self
    {
        return new self('Este pago ya está anulado.');
    }

    public static function coversDownPayment(): self
    {
        return new self('Este pago cubrió la inicial: para anularlo, registra antes el pago correcto de la inicial o cancela el apartado.');
    }
}
