<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageMetric;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Devuelve el acceso a alguien que lo había perdido (`REQ-ORG-005` RB-6).
 *
 * **Reactivar consume cupo igual que invitar** (SP-4): el techo del plan
 * cuenta personas con acceso, y volver a dárselo a alguien es exactamente eso.
 * Sin la comprobación, desactivar y reactivar sería la forma de rebasar
 * cualquier plan sin pagarlo, y la haría cualquiera sin proponérselo: bajas a
 * uno, invitas a dos, vuelves a subir al primero.
 *
 * **Y fuerza `must_change_password`** (RB-6). Es una precaución barata con un
 * motivo concreto: entre la baja y el alta puede haber pasado cualquier cosa
 * con esa contraseña —que la persona la reutilizara en otro sitio que después
 * se filtró, que la dejara escrita, que la conociera alguien que ya no debería
 * conocerla— y nadie lo va a comprobar. La alternativa es confiar durante años
 * en una credencial que estuvo sin vigilancia.
 *
 * No pasa por `LastOwnerGuard`: reactivar **suma** un `owner` activo o deja el
 * número como estaba, y esa invariante solo se puede romper restando.
 */
final class EnableTeamMemberAction
{
    use LogsSensitiveActivity;

    public function __construct(private readonly LimitEnforcer $limits) {}

    /**
     * @param  User  $member  a quien se le devuelve el acceso
     * @param  User  $actor  quien lo ordena
     *
     * @throws AuthorizationException si el actor no gobierna el equipo
     * @throws PlanLimitReachedException si el plan ya está lleno (SP-4)
     */
    public function handle(User $member, User $actor): User
    {
        Gate::forUser($actor)->authorize('update', $member);

        // Ya activo: reactivarlo le exigiría cambiar la contraseña sin que
        // haya pasado nada, que es la peor forma de no hacer nada.
        if ($member->disabled_at === null) {
            return $member;
        }

        return DB::transaction(function () use ($member, $actor): User {
            $this->limits->assertCanCreate(UsageMetric::Users);

            $member->forceFill([
                'disabled_at' => null,
                'must_change_password' => true,
            ])->save();

            $this->limits->synchronise(UsageMetric::Users);

            // RB-1 · devolverle el acceso a alguien es la operación simétrica
            // de retirárselo, y se audita igual: entre una y otra hay una
            // ventana en la que esa persona no debía entrar, y el registro es
            // lo que permite acotarla.
            $this->audit(
                AuditEvent::TeamMemberEnabled,
                subject: $member,
                actor: $actor,
                before: ['access' => 'desactivado'],
                after: ['access' => 'activo', 'role' => $member->role],
            );

            return $member;
        });
    }
}
