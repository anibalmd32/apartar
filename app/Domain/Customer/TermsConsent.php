<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * La evidencia de que la persona aceptó los términos al apartar
 * (`REQ-IDN-005` RB-2): cuándo y desde qué IP.
 *
 * ## Por qué no va dentro de `CustomerContactData`
 *
 * Porque no la declara la persona: la **captura el servidor**. El contacto es
 * lo que alguien escribe sobre sí mismo —y con sesión de cuenta, parte lo
 * impone la cuenta (`REQ-IDN-002` RB-2)—; el consentimiento es un instante y
 * una IP que solo sabe la petición, tomada del proxy de confianza
 * (`REQ-IDN-005` RB-6). Meterlos en el mismo DTO obligaría a inventar una
 * evidencia cada vez que alguien necesitara describir un contacto sin que
 * hubiera aceptación de por medio, que es exactamente la evidencia falsa que
 * esta clase existe para impedir.
 *
 * Así que viaja aparte, **como parámetro obligatorio** de
 * `ResolveCustomerAction::handle()`, y eso es lo que hace imposible —no
 * desaconsejado— crear o reutilizar una ficha sin ella: el tipo no admite
 * `null`, y esta clase no se construye con una IP que no lo sea.
 */
final readonly class TermsConsent
{
    public CarbonImmutable $acceptedAt;

    /**
     * @throws InvalidArgumentException si `$ipAddress` no es una IPv4 o IPv6
     */
    public function __construct(CarbonInterface $acceptedAt, public string $ipAddress)
    {
        if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException(sprintf(
                'La evidencia de consentimiento necesita la IP desde la que se aceptó (REQ-IDN-005 RB-2), y «%s» no es una.',
                $ipAddress,
            ));
        }

        // En la zona de la aplicación, que es la de la sesión de MySQL
        // ([05 §4]). El *cast* `datetime` escribe la hora de pared sin
        // convertir, así que un instante en UTC se guardaría cuatro horas
        // corrido: el mismo defecto que `DolarApiProvider` corrige en su borde.
        $this->acceptedAt = CarbonImmutable::instance($acceptedAt)->setTimezone(date_default_timezone_get());
    }

    /**
     * La aceptación que ocurre ahora, desde esa IP: la que construye el
     * formulario de apartado al validar la casilla (`T-045`).
     */
    public static function acceptedNowFrom(string $ipAddress): self
    {
        return new self(CarbonImmutable::now(), $ipAddress);
    }
}
