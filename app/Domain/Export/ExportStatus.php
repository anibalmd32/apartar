<?php

declare(strict_types=1);

namespace App\Domain\Export;

/**
 * Por dónde va una exportación (`REQ-BOF-043`): pedida y en cola, en curso,
 * lista para descargar, fallida, o caducada —su archivo ya se purgó (RB-7)—.
 */
enum ExportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';

    /**
     * Indica si todavía no ha terminado (RB-10: una en curso por organización
     * y tipo; una segunda petición devuelve esta).
     */
    public function isInProgress(): bool
    {
        return match ($this) {
            self::Queued, self::Processing => true,
            self::Completed, self::Failed, self::Expired => false,
        };
    }

    /**
     * Indica si su archivo existe y se puede descargar.
     */
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
