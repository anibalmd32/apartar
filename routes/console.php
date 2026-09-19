<?php

declare(strict_types=1);

use App\Jobs\FetchExchangeRate;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Comandos de consola y tareas programadas
|--------------------------------------------------------------------------
|
| Las catorce entradas del schedule de REQ-INF-003 RB-5 entran con la tarea de
| cada job, no todas de golpe: `layaways:expire` con T-047,
| `notifications:dispatch-reminders` con T-083, y así hasta cerrar al final de
| H8. Esta es la primera.
|
| El servicio `queue` ya ejecuta `schedule:work`, de modo que cada entrada
| empieza a correr en cuanto se registra aquí.
|
*/

/*
| Ingesta de la tasa de cambio (REQ-FX-002 RB-0 y RB-1).
|
| Cada 30 minutos, **una sola ejecución para toda la plataforma**: el job no
| recibe ninguna organización ni las recorre, porque la tasa del BCV es la
| misma para todos y escalar a 10.000 negocios no puede multiplicar las
| llamadas a la API externa.
|
| `withoutOverlapping()` aquí es el cinturón sobre los tirantes del
| `WithoutOverlapping` del propio job: este impide que el scheduler despache
| encima de una ejecución viva, y el del job impide que lo haga un reintento
| con backoff de 300 s. Los dos casos son reales y ninguno cubre al otro.
*/
Schedule::job(new FetchExchangeRate)
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->name('exchange-rate:fetch');
