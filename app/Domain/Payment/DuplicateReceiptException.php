<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use RuntimeException;
use Throwable;

final class DuplicateReceiptException extends RuntimeException
{
    public const STATUS = 422;

    public const MESSAGE = 'Este comprobante ya fue reportado.';

    public function __construct(
        public readonly DuplicateCriterion $criterion,
        ?Throwable $previous = null,
    ) {
        parent::__construct(self::MESSAGE, 0, $previous);
    }

    public function field(): string
    {
        return match ($this->criterion) {
            DuplicateCriterion::FileHash => 'file',
            DuplicateCriterion::BankReference => 'reference',
        };
    }
}
