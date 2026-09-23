<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| El cerrojo de las dos recetas (`T-003b`, `REQ-QAX-001`)
|------------------------------------------------------------------------------
|
| `composer check` y `npm run check` **no pueden correr a la vez**: `vite build`
| escribe en `public/` mientras `Storage::fake('public')` borra directorios, y
| salen ~25 fallos que no son del código. Se midió en `T-032`, donde costó una
| pasada entera de veinte minutos averiguar que el culpable no era el código.
|
| Estaba anotado en [91](../docs/specs/91-calidad-y-gates.md) y anotarlo no
| basta: quien lanza las dos a la vez es precisamente quien tiene prisa y no
| está leyendo la nota. Esto lo impide en vez de advertirlo.
|
| ## Por qué un archivo y no `flock`
|
| Los dos lados viven en runtimes distintos —PHP dentro del contenedor, Node en
| el host— y el árbol del proyecto es un bind mount entre Windows y Linux. Las
| semánticas de `flock` sobre ese montaje no son de fiar; la existencia de un
| archivo sí. Lo que se pierde es la atomicidad frente a dos lanzamientos en el
| mismo milisegundo, que no es el caso que esto viene a evitar.
|
| El cerrojo **caduca**: un proceso que muere sin soltarlo dejaría el árbol
| bloqueado para siempre, y eso sería peor que el problema. Pasado el plazo se
| ignora y se avisa.
|
| Uso: `php scripts/gate-lock.php acquire <nombre>` y `… release <nombre>`.
| El gemelo para Node es `scripts/gate-lock.mjs`, y los dos escriben y leen
| **el mismo archivo**: son dos implementaciones porque son dos runtimes, no
| porque sean dos cerrojos.
*/

$root = dirname(__DIR__);
$lock = $root.'/storage/framework/gate.lock';

/** Minutos tras los cuales un cerrojo se da por abandonado. */
const STALE_AFTER_SECONDS = 3600;

$action = $argv[1] ?? '';
$owner = $argv[2] ?? 'composer check';

if ($action === 'release') {
    @unlink($lock);

    exit(0);
}

if ($action !== 'acquire') {
    fwrite(STDERR, "Uso: php scripts/gate-lock.php acquire|release <nombre>\n");

    exit(2);
}

if (is_file($lock)) {
    $held = trim((string) file_get_contents($lock));
    $age = time() - (int) filemtime($lock);

    if ($age < STALE_AFTER_SECONDS) {
        fwrite(STDERR, sprintf(
            "\n  No se puede arrancar «%s»: «%s» lleva %d s corriendo.\n\n".
            "  Las dos recetas no pueden correr a la vez. `vite build` escribe en public/ mientras\n".
            "  Storage::fake('public') borra directorios, y salen ~25 fallos que no son del código\n".
            "  (medido en T-032). Espera a que termine y lánzala después.\n\n".
            "  Si aquello ya murió: php scripts/gate-lock.php release\n\n",
            $owner,
            $held === '' ? 'otra receta' : $held,
            $age,
        ));

        exit(1);
    }

    fwrite(STDERR, sprintf(
        "  Aviso: el cerrojo de «%s» llevaba %d s sin soltarse y se da por abandonado.\n",
        $held === '' ? 'otra receta' : $held,
        $age,
    ));
}

@mkdir(dirname($lock), 0777, true);
file_put_contents($lock, $owner);

exit(0);
