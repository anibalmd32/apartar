<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$lock = $root.'/storage/framework/gate.lock';

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
            ". Espera a que termine y lánzala después.\n\n".
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
