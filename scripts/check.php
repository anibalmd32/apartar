<?php

declare(strict_types=1);

$lock = __DIR__.'/gate-lock.php';
$php = escapeshellarg(PHP_BINARY);

passthru($php.' '.escapeshellarg($lock).' acquire '.escapeshellarg('composer check'), $acquired);

if ($acquired !== 0) {
    exit($acquired);
}

register_shutdown_function(static function () use ($php, $lock): void {
    passthru($php.' '.escapeshellarg($lock).' release', $ignored);
});

passthru('composer run --timeout=0 check:steps', $status);

exit($status);
