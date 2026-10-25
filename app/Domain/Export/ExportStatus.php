<?php

declare(strict_types=1);

namespace App\Domain\Export;

enum ExportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';

    public function isInProgress(): bool
    {
        return match ($this) {
            self::Queued, self::Processing => true,
            self::Completed, self::Failed, self::Expired => false,
        };
    }

    public function isDownloadable(): bool
    {
        return $this === self::Completed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En cola',
            self::Processing => 'Generando',
            self::Completed => 'Lista',
            self::Failed => 'Falló',
            self::Expired => 'Caducada',
        };
    }
}
