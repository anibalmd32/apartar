<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use DomainException;

/**
 * Documento y código de apartado que no abren nada (`REQ-IDN-008` RB-2, RB-5).
 *
 * **Una sola excepción y un solo mensaje para los tres fallos**: el código no
 * existe (SP-3), existe pero es de otra persona (SP-2), o el documento y el
 * código no son de la misma ficha (SP-1). Distinguirlos convertiría el
 * formulario en un oráculo: con el mensaje de «ese código existe pero no es
 * tuyo» bastaría probar códigos para saber cuáles están vivos, y con «esa
 * cédula no tiene apartados» bastaría probar cédulas —que en Venezuela son
 * semipúblicas— para saber quién compra dónde.
 *
 * El motivo concreto existe, pero viaja en `$reason` para el log, nunca hacia
 * la persona.
 */
final class AccessVerificationFailedException extends DomainException
{
    public const MESSAGE = 'No encontramos un apartado con esa cédula y ese código. Revisa los dos datos: el código está en tu confirmación y empieza por AP-.';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGE);
    }
}
