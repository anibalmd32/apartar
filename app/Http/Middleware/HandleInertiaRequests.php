<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\OrganizationCapability;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
            ],
            'teamMember' => $this->teamMember($request),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Quién está dentro y de qué negocio (`AuthenticatedTeamMember`,
     * `REQ-BOF-001`).
     *
     * Va aparte de `auth.user` y no en su lugar: `auth.user` es la fila cruda
     * que consumen las pantallas heredadas de perfil, y reescribirla obligaría
     * a tocarlas sin necesidad. Esto es lo que el backoffice necesita en
     * **todas** sus pantallas —el rol, para no pintar lo que va a dar `403`, y
     * el estado del negocio, para el aviso de suspensión— y por eso viaja en
     * las props compartidas y no en cada controlador.
     *
     * El estado de la organización se lee de la fila en cada petición, igual
     * que el rol (`REQ-ORG-003` RB-6): una suspensión que esperase al próximo
     * inicio de sesión dejaría al equipo creando cosas que ya no debería.
     *
     * **`capabilities` viaja con el rol y el menú se filtra con ella**
     * (RB-4). Antes cada sección declaraba a mano un `ownerOnly`, de modo que
     * la frontera de RB-1 estaba escrita dos veces: en
     * `OrganizationCapability::requiresOwner()` y en el `.tsx`. Coincidían —lo
     * detectó `T-013b` cuando eran dos entradas— y el problema no era el hoy
     * sino el día en que una capacidad cambiara de lado: el enum lo sabría y
     * el menú no, sin un solo test en rojo. El síntoma sería un `member`
     * viendo una sección que le va a dar `403`, o un `owner` sin ver algo que
     * sí puede hacer.
     *
     * Y lo de siempre: ocultar en la UI **no** es control de acceso. Esto solo
     * evita ofrecer una puerta que la policy va a cerrar.
     *
     * @return array<string, mixed>|null
     */
    private function teamMember(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $organization = $user->organization;

        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'capabilities' => array_map(
                fn (OrganizationCapability $capability): string => $capability->value,
                $user->role->capabilities(),
            ),
            'mustChangePassword' => $user->must_change_password,
            'organization' => [
                'name' => $organization->name,
                'slug' => $organization->slug,
                'status' => $organization->status->value,
            ],
        ];
    }
}
