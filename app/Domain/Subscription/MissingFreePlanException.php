<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use RuntimeException;

final class MissingFreePlanException extends RuntimeException
{
    public static function withCode(string $code): self
    {
        return new self(sprintf(
            'No existe el plan «%s» en la tabla plans, así que no se puede dar de alta a nadie. '
            .'Siembra el catálogo de planes con `php artisan db:seed --class=Database\\Seeders\\PlanSeeder`.',
            $code,
        ));
    }
}
