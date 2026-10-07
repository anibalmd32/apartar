<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * Por cuál de los dos criterios de `REQ-PAY-003` RB-2 se bloqueó un
 * comprobante repetido.
 *
 * Son dos y no uno porque cada uno caza lo que el otro deja pasar: el hash
 * no ve una captura recortada o reguardada del mismo pago (SP-3), y la
 * pareja banco y referencia no existe en efectivo (RB-3). Lo que cambia entre
 * ellos, para quien lo enseña, es **qué campo del formulario** tiene que
 * señalar.
 */
enum DuplicateCriterion: string
{
    /** (a) El `sha256` del archivo tal como se subió ya está en esa organización. */
    case FileHash = 'file_hash';

    /** (b) La pareja (`bank`, `reference`) ya está en esa organización. */
    case BankReference = 'bank_reference';
}
