<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\PaymentAccount;
use RuntimeException;

final class PaymentAccountInUseException extends RuntimeException
{
    private function __construct(
        public readonly PaymentAccount $account,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function deactivatedInstead(PaymentAccount $account): self
    {
        return new self($account, sprintf(
            'La cuenta %s tiene comprobantes de pago asociados, así que no se ha eliminado: queda desactivada '
            .'y deja de ofrecerse en los apartados nuevos. Sus comprobantes siguen mostrando a dónde se pagó.',
            self::describe($account),
        ));
    }

    private static function describe(PaymentAccount $account): string
    {
        return sprintf('de %s (%s)', $account->method->label(), $account->identifier);
    }
}
