<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use RuntimeException;

final class PhotoReviewAlreadyClosedException extends RuntimeException
{
    public function __construct(public readonly PhotoReviewStatus $status)
    {
        parent::__construct(sprintf(
            'Esta revisión ya fue %s por otra persona.',
            $status === PhotoReviewStatus::Approved ? 'aprobada' : 'rechazada',
        ));
    }
}
