<?php

declare(strict_types=1);

namespace App\Domain\Settings;

enum SettingScope: string
{
    case Immediate = 'immediate';

    case Daily = 'daily';

    case FutureOnly = 'future_only';

    public function description(): string
    {
        return match ($this) {
            self::Immediate => 'Se aplica desde la siguiente operación.',
            self::Daily => 'Se reevalúa en el recálculo de mañana, sobre lo que siga vigente.',
            self::FutureOnly => 'Solo afecta a lo que se cree a partir de ahora: no mueve fechas ya fijadas.',
        };
    }
}
