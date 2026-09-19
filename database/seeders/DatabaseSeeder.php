<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Siembra lo que **cualquier** entorno necesita para funcionar.
 *
 * Son dos, y las dos hacen falta para que la plataforma funcione desde el
 * primer minuto:
 *
 *  - **`PlanSeeder`**: sin el plan Free no se puede dar de alta a nadie
 *    (`REQ-SUB-002` SP-1), así que una base migrada y sin sembrar es una base
 *    en la que el registro falla.
 *  - **`ExchangeRateSeeder`**: sin ninguna tasa, el catálogo arranca en
 *    dólares puros hasta la primera vuelta del job, que puede tardar media
 *    hora ([05 §6.4], `REQ-FX-005` SP-1).
 *
 * Las dos son **idempotentes**, de modo que volver a ejecutarlas es
 * inofensivo.
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
        $this->call(ExchangeRateSeeder::class);
    }
}
