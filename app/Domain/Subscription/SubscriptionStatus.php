<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

/**
 * Estado de la suscripción de una organización (`REQ-SUB-007`).
 *
 * La secuencia del impago es: factura vencida → `past_due` con su gracia →
 * gracia agotada → `suspended`. Pagar en cualquier punto la levanta de
 * inmediato (RB-5).
 *
 * **La regla que no se negocia (`Q7`)**: ningún estado de esta lista
 * interrumpe jamás un apartado en curso, el portal del cliente final, la
 * subida de comprobantes ni el chat con los clientes (RB-3). Los dos métodos
 * de abajo hablan **solo del equipo del negocio**. Si un negocio deja de
 * pagar, el perjudicado no puede ser el cliente que le debe dinero a él.
 */
enum SubscriptionStatus: string
{
    /** Al día. */
    case Active = 'active';

    /** Factura vencida, dentro de la gracia: no se pierde nada todavía. */
    case PastDue = 'past_due';

    /** Bajada de plan pendiente de aplicar al cierre del período. */
    case Grace = 'grace';

    /** Gracia agotada: el backoffice queda en modo de cobrar y poco más. */
    case Suspended = 'suspended';

    /** Baja efectiva. */
    case Cancelled = 'cancelled';

    /**
     * Indica si el equipo del negocio puede entrar al backoffice.
     *
     * `suspended` dice que **sí**, y no es una contradicción con la
     * suspensión: `REQ-ORG-008` RB-3 conserva ahí la lectura de la cobranza y
     * la aprobación de comprobantes, porque cobrar lo que ya le deben no puede
     * quedar bloqueado por deberle una suscripción a la plataforma. Lo que
     * pierde una organización suspendida es **crear**, que es lo que responde
     * el otro método.
     */
    public function allowsTeamAccess(): bool
    {
        return match ($this) {
            self::Active, self::PastDue, self::Grace, self::Suspended => true,
            self::Cancelled => false,
        };
    }

    /**
     * Indica si el equipo puede crear cosas nuevas.
     *
     * En `suspended` es `false` **para todo**, con independencia de los
     * contadores (`REQ-SUB-004` RB-8). En `past_due` es `true`: la gracia
     * existe precisamente para que un atraso de pago no paralice el negocio
     * (RB-2).
     */
    public function allowsCreation(): bool
    {
        return match ($this) {
            self::Active, self::PastDue, self::Grace => true,
            self::Suspended, self::Cancelled => false,
        };
    }

    /**
     * Indica si hay una deuda que regularizar.
     */
    public function isDelinquent(): bool
    {
        return $this === self::PastDue || $this === self::Suspended;
    }
}
