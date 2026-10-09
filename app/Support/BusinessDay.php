<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Un `AAAA-MM-DD` de una query string como día de una zona, o `null` si no es
 * una fecha que exista: el 31 de febrero no se convierte en el 3 de marzo.
 *
 * Lo usan los filtros de fecha de las tablas del backoffice (la bandeja de
 * comprobantes, la cobranza), que reciben días del negocio.
 */
final class BusinessDay
{
    public static function parse(mixed $raw, string $timezone): ?CarbonImmutable
    {
        if (! is_string($raw) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $parts) !== 1) {
            return null;
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $raw, $timezone);

        return $day instanceof CarbonImmutable ? $day : null;
    }
}
