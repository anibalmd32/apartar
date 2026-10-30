<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use RuntimeException;
use Throwable;

final class DuplicateReceiptException extends RuntimeException
{
    public const STATUS = 422;

    public const MESSAGE = 'Este comprobante ya fue reportado.';

    public const RECORDED_REFERENCE_MESSAGE = 'Ese banco y esa referencia ya están en otro pago o comprobante de tu negocio.';

    public const RECORDED_FILE_MESSAGE = 'Ese archivo ya está adjunto a otro pago o comprobante de tu negocio.';

    public function __construct(
        public readonly DuplicateCriterion $criterion,
        ?Throwable $previous = null,
        string $message = self::MESSAGE,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function recordedByBusiness(DuplicateCriterion $criterion, ?Throwable $previous = null): self
    {
        return new self($criterion, $previous, self::recordedMessageFor($criterion));
    }

    public static function recordedMessageFor(DuplicateCriterion $criterion): string
    {
        return match ($criterion) {
            DuplicateCriterion::FileHash => self::RECORDED_FILE_MESSAGE,
            DuplicateCriterion::BankReference => self::RECORDED_REFERENCE_MESSAGE,
        };
    }

    public function field(): string
    {
        return match ($this->criterion) {
            DuplicateCriterion::FileHash => 'file',
            DuplicateCriterion::BankReference => 'reference',
        };
    }
}
