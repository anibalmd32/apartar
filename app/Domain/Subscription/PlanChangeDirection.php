<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Actions\Subscription\StartFreeSubscriptionAction;
use App\Models\Plan;

/**
 * Hacia dónde va un cambio de plan (`REQ-SUB-005`, `T-068`).
 *
 * Es lo primero que decide `ChangePlanAction`, porque las tres direcciones no
 * se parecen en nada: subir es inmediato (RB-2), bajar se programa para el fin
 * del período (RB-3), y al mismo plan no es nada —o, si había una bajada
 * programada, es revertirla (RB-7, SP-4)—.
 */
enum PlanChangeDirection: string
{
    case Upgrade = 'upgrade';
    case Downgrade = 'downgrade';
    case Same = 'same';

    /**
     * Dirección del cambio de `$current` a `$target`, por su posición en el
     * escaparate (`Plan::ranksAbove()`).
     */
    public static function between(Plan $current, Plan $target): self
    {
        if ($current->is($target)) {
            return self::Same;
        }

        // Free es el suelo (`REQ-SUB-002` RB-6): ir a Free es siempre bajar,
        // aunque se venga de un plan negociado con la posición por defecto
        // (cero). Sin esto, la subida «a Free» se aplicaría en el acto, sin
        // previsualización, sin marcar la baja y con fecha de fin en Free.
        if ($target->is(StartFreeSubscriptionAction::freePlan())) {
            return self::Downgrade;
        }

        // Empate de posición: bajar, que es lo que no quita nada en el acto.
        return $target->ranksAbove($current) ? self::Upgrade : self::Downgrade;
    }
}
