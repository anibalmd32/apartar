<?php

declare(strict_types=1);

use App\Domain\Catalog\PhotoReviewAlreadyClosedException;
use App\Domain\Catalog\PhotoReviewStatus;

it('dice cómo quedó la revisión que otra persona cerró primero', function (): void {
    expect((new PhotoReviewAlreadyClosedException(PhotoReviewStatus::Approved))->getMessage())
        ->toBe('Esta revisión ya fue aprobada por otra persona.')
        ->and((new PhotoReviewAlreadyClosedException(PhotoReviewStatus::Rejected))->getMessage())
        ->toBe('Esta revisión ya fue rechazada por otra persona.');
});
