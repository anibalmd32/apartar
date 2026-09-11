<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Comandos de consola y tareas programadas
|--------------------------------------------------------------------------
|
| Vacío a propósito (T-002). Las catorce entradas del schedule de
| REQ-INF-003 RB-5 entran con la tarea de cada job, no todas de golpe:
| `layaways:expire` con T-047, `notifications:dispatch-reminders` con T-083,
| y así hasta cerrar al final de H8.
|
| El servicio `queue` ya ejecuta `schedule:work`, de modo que cada entrada
| empieza a correr en cuanto se registra aquí.
|
*/
