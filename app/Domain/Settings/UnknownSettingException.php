<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use RuntimeException;

final class UnknownSettingException extends RuntimeException
{
    public static function named(string $key): self
    {
        $known = implode(', ', array_column(SettingKey::cases(), 'value'));

        return new self(sprintf(
            'No existe el ajuste «%s». Las claves de organization_settings son: %s.',
            $key,
            $known,
        ));
    }
}
