<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| `composer check` con el cerrojo puesto (`T-003b`)
|------------------------------------------------------------------------------
|
| Coge el cerrojo, corre `composer check:steps` —que es donde vive el orden de
| los gates, a la vista en `composer.json`— y lo suelta **pase lo que pase**.
|
| El envoltorio existe solo por ese «pase lo que pase»: los scripts de Composer
| se detienen en el primer fallo y no tienen `finally`, así que un `lint` en
| rojo dejaría el cerrojo cogido y la siguiente receta se negaría a arrancar por
| culpa de un proceso que ya no existe. El orden de los pasos **no** se mueve
| aquí; si se moviera, `composer.json` dejaría de ser el sitio donde se lee qué
| corre antes que qué.
|
| El gemelo del frontend es `scripts/check.mjs`, y los dos cerrojos son el
| mismo archivo.
*/

$lock = __DIR__.'/gate-lock.php';
$php = escapeshellarg(PHP_BINARY);

passthru($php.' '.escapeshellarg($lock).' acquire '.escapeshellarg('composer check'), $acquired);

if ($acquired !== 0) {
    exit($acquired);
}

// Si esto se va por una señal o un fatal, el cerrojo se suelta igual. Lo que
// no cubre es un `kill -9`, y para eso el cerrojo caduca solo.
register_shutdown_function(static function () use ($php, $lock): void {
    passthru($php.' '.escapeshellarg($lock).' release', $ignored);
});

passthru('composer run --timeout=0 check:steps', $status);

exit($status);
