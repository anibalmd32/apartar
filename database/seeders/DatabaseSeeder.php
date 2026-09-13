<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Siembra lo que **cualquier** entorno necesita para funcionar.
 *
 * Hoy es solo el catálogo de planes, y no es poco: sin el plan Free no se
 * puede dar de alta a nadie (`REQ-SUB-002` SP-1), así que una base migrada y
 * sin sembrar es una base en la que el registro falla. `PlanSeeder` es
 * idempotente, de modo que volver a ejecutarlo es inofensivo.
 *
 * El escenario de desarrollo —dos organizaciones con datos de mentira— es
 * `DevSeeder` y llega con `T-016`: son cosas distintas y no deben mezclarse,
 * porque esta sí se ejecuta en producción.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanSeeder::class);
    }
}
