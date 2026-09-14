<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Tenancy\TeamInvitation;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\Auth\AcceptInvitationRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Donde una invitación se convierte en un acceso (`REQ-ORG-004` RB-5).
 *
 * ## Cómo sabe de qué organización es quien llega
 *
 * `users` es una tabla del tenant y quien abre este enlace no tiene sesión de
 * la que sacar la organización: buscar el token sin más lanzaría
 * `MissingTenantContextException`. La respuesta es que **el token la lleva
 * dentro** (`TeamInvitation`): se lee el prefijo, se resuelve la organización
 * en `organizations` —que no está sujeta al tenant— y a partir de ahí se busca
 * dentro de ella, con el filtro puesto.
 *
 * Por eso estas dos rutas se declaran `outside-tenancy` y no inventan una
 * sexta superficie en la tabla de `REQ-ORG-002` RB-4: no pertenecen a ninguna
 * —igual que el registro, la organización aquí no sale de una sesión ni de una
 * URL de catálogo— y el tenant se fija **dentro**, con `runFor()`, que es
 * exactamente lo que hace `RegisterOrganizationAction` con la organización
 * recién creada.
 *
 * ## El enlace caducado no es un error
 *
 * SP-3 es el caso que suele quedar mal resuelto, y quien llega ahí no ha hecho
 * nada mal: es alguien que tardó tres días en abrir el correo. Así que no hay
 * `404` ni `403`, hay una pantalla que explica qué pasó y cómo salir —pedir el
 * reenvío a quien invitó—, y el token **se marca consumido**.
 *
 * Fíjese en qué **no** consume: un enlace vivo abierto con `GET` no se gasta.
 * Si `show()` consumiera, el primer antivirus de correo que visita los enlaces
 * para comprobarlos quemaría la invitación antes de que la persona llegara a
 * verla. Lo que gasta el enlace es aceptar.
 */
final class AcceptInvitationController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * La pantalla para elegir contraseña, o la de enlace caducado.
     */
    public function show(string $token): Response
    {
        $organization = $this->organizationOf($token);
        $member = $this->pendingMemberFor($token, $organization);

        if (! $member instanceof User) {
            return $this->expired($organization);
        }

        if ($this->hasExpired($member)) {
            // SP-3 · se marca consumido al mostrarlo. La fila se queda: la
            // persona sigue invitada y ocupando su cupo, y un reenvío le
            // emite otro enlace sin volver a crearla.
            $this->context->runFor(
                $organization,
                fn () => $member->forceFill(['invitation_token_hash' => null])->save(),
            );

            return $this->expired($organization);
        }

        return Inertia::render('backoffice/auth/AcceptInvitation', [
            'token' => $token,
            'name' => $member->name,
            'email' => $member->email,
            'organizationName' => $organization?->name,
        ]);
    }

    /**
     * Fija la contraseña, deja a la persona activa y la mete dentro (RB-5).
     */
    public function store(AcceptInvitationRequest $request, string $token): RedirectResponse
    {
        $organization = $this->organizationOf($token);
        $member = $this->pendingMemberFor($token, $organization);

        if (! $member instanceof User || $this->hasExpired($member) || ! $organization instanceof Organization) {
            // El enlace se caducó entre que se pintó el formulario y se envió.
            // Se vuelve a la misma pantalla, que es la que sabe explicarlo.
            return to_route('team.invitation.show', ['token' => $token]);
        }

        $this->context->runFor($organization, function () use ($member, $request): void {
            DB::transaction(function () use ($member, $request): void {
                $member->forceFill([
                    'password' => (string) $request->string('password'),
                    // El enlace se gasta aquí, y solo aquí (RB-4).
                    'invitation_token_hash' => null,
                    'invitation_expires_at' => null,
                    // Quien acaba de elegir su contraseña no tiene ninguna que
                    // rotar: `must_change_password` es para las asignadas por
                    // otro y para la reactivación (`REQ-ORG-005` RB-6).
                    'must_change_password' => false,
                    // Abrir un enlace que solo llegó a ese buzón **es** la
                    // prueba de control de la dirección. Mandar después un
                    // segundo correo de verificación pediría demostrar dos
                    // veces lo mismo, y el cupo del plan ya está consumido
                    // desde que se invitó, así que no hay nada que proteger
                    // dejándolo sin verificar.
                    'email_verified_at' => $member->email_verified_at ?? now(),
                ])->save();
            });
        });

        // El cupo **no** se toca: lo consumió la invitación (RB-2). Aceptarla
        // no suma a nadie, convierte a quien ya contaba.

        Auth::guard('web')->login($member);

        $request->session()->regenerate();

        // SP-5 · si la organización quedó suspendida mientras tanto, la
        // persona entra igual y se encuentra el aviso: la cadena de
        // `REQ-BOF-001` decide qué puede hacer dentro, y no es asunto de esta
        // pantalla adelantar esa decisión.
        return redirect()->intended(route('dashboard'));
    }

    /**
     * Organización a la que pertenece el token, leída de su prefijo.
     *
     * `organizations` no lleva el trait del tenant, así que esta consulta se
     * puede hacer sin organización fijada. Es justo lo que permite que el
     * resto del método ya trabaje dentro de una.
     */
    private function organizationOf(string $token): ?Organization
    {
        $organizationId = TeamInvitation::organizationIdIn($token);

        return $organizationId === null
            ? null
            : Organization::query()->find($organizationId);
    }

    /**
     * Invitado al que apunta ese token, si sigue pendiente.
     *
     * Se busca por el **hash** y dentro de la organización del prefijo, de
     * modo que la consulta sale filtrada por el global scope como cualquier
     * otra del tenant. Un token de otra organización no encuentra nada aunque
     * su hash existiera en la tabla.
     */
    private function pendingMemberFor(string $token, ?Organization $organization): ?User
    {
        if (! $organization instanceof Organization) {
            return null;
        }

        return $this->context->runFor($organization, fn (): ?User => User::query()
            ->where('invitation_token_hash', TeamInvitation::hash($token))
            ->whereNull('password')
            ->whereNull('disabled_at')
            ->first());
    }

    private function hasExpired(User $member): bool
    {
        return $member->invitation_expires_at === null
            || $member->invitation_expires_at->isPast();
    }

    /**
     * La pantalla de SP-3: qué pasó y a quién pedírselo.
     *
     * No lleva un botón que reenvíe por sí solo, y es deliberado: sería un
     * endpoint sin autenticar capaz de disparar correos a partir de un token
     * cualquiera, y quien tiene que decidir si esa persona sigue entrando al
     * equipo es el `owner`. El correo del `owner` tampoco se muestra —sería
     * revelar un dato de la organización a quien todavía no está dentro—; se
     * le dice que lo pida a quien le invitó, que es alguien que conoce.
     */
    private function expired(?Organization $organization): Response
    {
        return Inertia::render('backoffice/auth/InvitationExpired', [
            'organizationName' => $organization?->name,
        ]);
    }
}
