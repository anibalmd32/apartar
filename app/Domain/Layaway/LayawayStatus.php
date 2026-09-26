<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

/**
 * Los seis estados de un apartado (`REQ-LAY-005`).
 *
 * Es la pieza de la que dependen la cobranza, los reportes y el cupo del plan:
 * si un apartado pudiera estar en un estado que nadie previó, todo lo que se
 * apoya en el estado dejaría de ser cierto a la vez. Por eso las cuatro
 * preguntas que se le hacen están aquí, junto a los casos, y cada una es un
 * `match` **sin `default`**: un séptimo estado no compila en silencio del lado
 * cómodo, obliga a decidir en las cuatro.
 *
 * Se persiste en `layaways.status` como `VARCHAR` ([02 §5]), y **solo lo
 * escribe `LayawayStateMachine`** (RB-2): ver el modelo `Layaway`.
 */
enum LayawayStatus: string
{
    /** Recién creado; espera la inicial (`REQ-LAY-001` RB-7). */
    case PendingDownPayment = 'pending_down_payment';

    /** Inicial aprobada y cronograma generado (`REQ-LAY-004`). */
    case Active = 'active';

    /** Alguna cuota vencida pasada la gracia (`REQ-LAY-007`). */
    case Overdue = 'overdue';

    /** Saldado: `remaining_cents` dentro de la tolerancia (RB-5). */
    case Completed = 'completed';

    /** Cancelado por el negocio (`REQ-LAY-009`). */
    case Cancelled = 'cancelled';

    /** La inicial no llegó a tiempo (`REQ-LAY-006`). */
    case Expired = 'expired';

    /**
     * Los estados a los que se puede ir desde este: la matriz de RB-1, literal.
     *
     * `overdue` ⇄ `active` es la única pareja que va y vuelve (RB-4). Los tres
     * terminales no van a ninguna parte (RB-8): no existe reapertura, y marcar
     * un `completed` como entregado no es una transición, es una marca
     * (`REQ-LAY-010`).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingDownPayment => [self::Active, self::Expired, self::Cancelled],
            self::Active => [self::Overdue, self::Completed, self::Cancelled],
            self::Overdue => [self::Active, self::Completed, self::Cancelled],
            self::Completed, self::Cancelled, self::Expired => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Si ya no sale de aquí (RB-8).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::PendingDownPayment, self::Active, self::Overdue => false,
            self::Completed, self::Cancelled, self::Expired => true,
        };
    }

    /**
     * Si admite comprobantes y pagos (RB-3): los tres vivos, incluido el que
     * espera la inicial, que es justo el primer pago.
     */
    public function acceptsPayments(): bool
    {
        return match ($this) {
            self::PendingDownPayment, self::Active, self::Overdue => true,
            self::Completed, self::Cancelled, self::Expired => false,
        };
    }

    /**
     * Si consume cupo del límite `active_layaways` del plan (RB-6,
     * `REQ-SUB-003` RB-2).
     *
     * Coincide hoy con `acceptsPayments()` y aun así es otra pregunta: una es
     * de cobranza y otra de suscripción, y el día que un estado nuevo las
     * separe cada una tiene que poder responder lo suyo.
     */
    public function countsTowardPlanLimit(): bool
    {
        return match ($this) {
            self::PendingDownPayment, self::Active, self::Overdue => true,
            self::Completed, self::Cancelled, self::Expired => false,
        };
    }

    /**
     * Si llegar aquí devuelve la unidad al stock (`REQ-LAY-003` RB-2).
     *
     * Expirar y cancelar, sí. **Completar, no**, y no es un olvido: completar
     * significa que la unidad se entregó, y devolverla inventaría una nevera.
     * Es la razón por la que esto no se deduce de `isTerminal()`, aunque los
     * tres terminales parezcan la misma cosa.
     */
    public function releasesStock(): bool
    {
        return match ($this) {
            self::Expired, self::Cancelled => true,
            self::PendingDownPayment, self::Active, self::Overdue, self::Completed => false,
        };
    }

    /**
     * Los estados cuyos apartados tienen la unidad inmovilizada: los no
     * terminales (`REQ-LAY-003` RB-6).
     *
     * **Se deriva de `isTerminal()`** y no se escribe aparte: dos listas de
     * los mismos estados acabarían discrepando el día que llegue un séptimo.
     *
     * @return list<self>
     */
    public static function holdingStock(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => ! $status->isTerminal()));
    }
}
