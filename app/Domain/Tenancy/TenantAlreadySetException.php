<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

/**
 * Se intentó cambiar de organización a mitad de una petición o de un job.
 *
 * `REQ-ORG-002` SP-6. No es rigidez: si el tenant cambia a mitad de camino es
 * que algo se resolvió mal antes, y las consultas que ya se ejecutaron
 * salieron con el tenant anterior. Fallar es lo correcto; "reajustar"
 * convertiría un error de resolución en una fuga parcial e indetectable, con
 * media respuesta construida con datos de una organización y media con los de
 * otra.
 *
 * Fijar **la misma** organización dos veces no lanza nada: es idempotente.
 *
 * Para ejecutar deliberadamente un bloque en otra organización —un comando de
 * mantenimiento que recorre todas, por ejemplo— existe
 * `TenantContext::runFor()`, que restaura el estado anterior al salir.
 */
final class TenantAlreadySetException extends RuntimeException
{
    /**
     * @param  int  $current  organización ya fijada
     * @param  int  $attempted  organización que se intentó fijar
     */
    public static function cannotSwitch(int $current, int $attempted): self
    {
        return new self(sprintf(
            'El tenant ya está fijado en la organización %d y se intentó cambiarlo a la %d. '
            .'Usa TenantContext::runFor() si de verdad necesitas ejecutar algo en otra organización.',
            $current,
            $attempted,
        ));
    }
}
