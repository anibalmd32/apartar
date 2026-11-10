<?php

declare(strict_types=1);

use App\Domain\Catalog\PhotoReviewStatus;

it('solo la revisión aprobada deja publicar', function (): void {
    expect(PhotoReviewStatus::Pending->blocksPublishing())->toBeTrue()
        ->and(PhotoReviewStatus::Rejected->blocksPublishing())->toBeTrue()
        ->and(PhotoReviewStatus::Approved->blocksPublishing())->toBeFalse();
});

it('tiene un nombre en español para cada estado', function (): void {
    expect(PhotoReviewStatus::Pending->label())->toBe('En revisión')
        ->and(PhotoReviewStatus::Approved->label())->toBe('Aprobada')
        ->and(PhotoReviewStatus::Rejected->label())->toBe('Rechazada');
});
