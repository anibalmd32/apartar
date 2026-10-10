<?php

declare(strict_types=1);

use App\Domain\Export\ExportStatus;

/**
 * `REQ-BOF-043` RB-10 · «en curso» es en cola o generándose; solo una
 * terminada se descarga (SP-4: la caducada ya no tiene archivo).
 */
it('sabe si está en curso y si se descarga', function (ExportStatus $status, bool $inProgress, bool $downloadable): void {
    expect($status->isInProgress())->toBe($inProgress)
        ->and($status->isDownloadable())->toBe($downloadable)
        ->and($status->label())->not->toBe('');
})->with([
    [ExportStatus::Queued, true, false],
    [ExportStatus::Processing, true, false],
    [ExportStatus::Completed, false, true],
    [ExportStatus::Failed, false, false],
    [ExportStatus::Expired, false, false],
]);
