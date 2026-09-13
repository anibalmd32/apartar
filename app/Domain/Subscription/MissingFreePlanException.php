<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use RuntimeException;

/**
 * No hay plan Free en `plans` (`REQ-SUB-002` SP-1).
 *
 * Es un fallo de instalación —la base no se sembró—, no un caso de negocio, y
 * por eso el registro **falla entero** en lugar de crear la organización sin
 * plan. Una organización sin suscripción es el estado que `REQ-SUB-002` RB-1 y
 * RB-6 prohíben: no tiene límites que comprobar, no aparece en ningún informe
 * de plataforma, y el error aparecería días después y lejos de aquí.
 *
 * El mensaje nombra el remedio porque quien lo lee suele estar levantando un
 * entorno por primera vez.
 */
final class MissingFreePlanException extends RuntimeException
{
    /**
     * @param  string  $code  código del plan que se buscaba
     */
    public static function withCode(string $code): self
    {
        return new self(sprintf(
            'No existe el plan «%s» en la tabla plans, así que no se puede dar de alta a nadie. '
            .'Siembra el catálogo de planes con `php artisan db:seed --class=Database\\Seeders\\PlanSeeder`.',
            $code,
        ));
    }
}
