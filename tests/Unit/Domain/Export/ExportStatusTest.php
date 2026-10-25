<?php

declare(strict_types=1);

use App\Domain\Export\ExportStatus;

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
