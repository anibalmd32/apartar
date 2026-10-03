<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\Customer;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * Abre la sesión acotada del portal sobre una ficha (`REQ-IDN-004`,
 * `REQ-IDN-008` RB-4, `REQ-IDN-003` RB-7).
 *
 * Las dos vías de la sesión acotada —documento + código (`T-049`) y el enlace
 * por correo (`T-050`)— terminan aquí, y por eso es un solo sitio: el guard
 * `customer`, con «recuérdame» de **30 días**. Si cada vía abriera la sesión a
 * su manera, el día que cambie el plazo una de las dos se quedaría con el
 * viejo sin que nada lo dijera.
 *
 * No verifica nada: quien llama ya comprobó la prueba que corresponda.
 */
final class PortalLogin
{
    /** Treinta días, en minutos. */
    public const REMEMBER_MINUTES = 60 * 24 * 30;

    public function open(Customer $customer): void
    {
        $guard = Auth::guard('customer');

        if (! $guard instanceof SessionGuard) {
            // Un guard `customer` que no es de sesión es una configuración
            // rota: mejor un fallo que una sesión que no se abre.
            throw new LogicException('El guard «customer» tiene que ser de sesión (config/auth.php).');
        }

        $guard->setRememberDuration(self::REMEMBER_MINUTES);
        $guard->login($customer, remember: true);
    }
}
