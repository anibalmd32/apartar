<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Exchange\CurrentRate;
use App\Domain\Money\CurrencyDisplay;
use App\Domain\Settings\SettingKey;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Tenancy\OrganizationCapability;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Settings $settings,
    ) {}

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
                // Solo el usuario del equipo, sea cual sea el guard por
                // defecto. Hoy es `web`, y para un cliente da `null`; pero si
                // fuera `customer` —como en los tests, tras `actingAs(...,
                // 'customer')`— la ficha saldría entera con su `id`, su
                // `account_id` y la IP de los términos, que es lo que
                // `REQ-IDN-004` RB-4 y `REQ-LAY-022` RB-6 prohíben. El portal
                // pinta su sesión con `portal`, sin identificadores (`T-061`).
                'user' => $request->user() instanceof User ? $request->user() : null,
                // `REQ-IDN-004` · la sesión del portal, perezosa: solo se
                // resuelve al pintar, cuando el tenant ya está fijado.
                'portal' => fn (): ?array => $this->portalSession(),
            ],
            'teamMember' => $this->teamMember($request),
            'currency' => $this->currency($request),
            'status' => $request->session()->get('status'),
            // `REQ-SUB-003` RB-6, `REQ-SUB-004` (`T-067`) · el plan y su
            // consumo, para `usePlanLimits` y sus dos componentes. Perezoso:
            // solo se resuelve al pintar, y solo para el equipo.
            'planState' => fn (): ?array => $this->planState($request),
            // El último rechazo por plan, para `UpgradePrompt`: lo deja en la
            // sesión el render de las excepciones de límite (`bootstrap/app.php`).
            'planLimit' => $request->session()->get('planLimit'),
            'planFeature' => $request->session()->get('planFeature'),
            'planBlocked' => $request->session()->get('planBlocked'),
        ]);
    }

    /**
     * El estado del plan de la organización del equipo, o `null` fuera del
     * backoffice o en una base a medio sembrar —una organización sin
     * suscripción no puede tumbar todas sus páginas por esto—.
     *
     * @return array<string, mixed>|null
     */
    private function planState(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->context->hasOrganization()) {
            return null;
        }

        // El tenant fijado puede ser **otro negocio**: alguien del equipo que,
        // con la sesión abierta, mira el catálogo público de la competencia
        // tiene fijada la organización de ese catálogo, y esto le serviría su
        // plan, su consumo y su estado de pago. Solo el de la propia, como
        // `pendingReceipts()` (revisión adversarial de `T-067`).
        if ((int) $user->organization_id !== $this->context->organizationId()) {
            return null;
        }

        if (! Subscription::query()->exists()) {
            return null;
        }

        return app(LimitEnforcer::class)->state()->toArray();
    }

    /**
     * La tasa vigente y en qué orden se leen las monedas (`REQ-FX-004`,
     * `REQ-FX-005`).
     *
     * **Va en las props compartidas y no en cada controlador** porque los dos
     * datos atraviesan todas las superficies con precios —catálogo, portal,
     * feed y backoffice—, y repetirlos controlador a controlador garantizaría
     * que el día que aparezca una pantalla nueva se le olvide a alguien: el
     * síntoma sería una página con precios sin sello, o con el toggle muerto.
     *
     * **La preferencia la resuelve el servidor** (RB-4). Si se descubriera al
     * montar el componente, la página abriría en dólares y saltaría a
     * bolívares un instante después —en cada visita de todo el que eligiera
     * bolívares—, que es el parpadeo que `ASU-FX-08` quiere evitar.
     *
     * La zona horaria viaja con ellos porque el sello escribe una hora y hay
     * **tres** candidatas: la del navegador, la de la aplicación y la del
     * negocio. Manda la del negocio (`organizations.timezone`): el BCV publica
     * en hora de Venezuela y el catálogo es de un negocio venezolano, así que
     * un cliente que mira desde Madrid tiene que leer la misma hora que dice
     * el BCV y no las cinco de la tarde.
     *
     * @return array<string, mixed>
     */
    private function currency(Request $request): array
    {
        return [
            'display' => $this->currencyDisplay($request)->value,
            'rate' => app(CurrentRate::class)->view()->toArray(),
            'timezone' => $this->timezone(),
        ];
    }

    /**
     * Qué moneda va primero, en el orden que fija RB-5.
     *
     * Cookie válida → ajuste de la organización → default de plataforma.
     *
     * **La cookie no se parte por organización y no se reescribe al cambiar de
     * una a otra** (RB-3): la preferencia es de la persona. Quien navega el
     * catálogo de dos negocios en bolívares no quiere volver a pulsar el
     * toggle en el segundo, y `currency_display_default` solo decide el
     * arranque de quien todavía no ha elegido nada.
     *
     * Una cookie con un valor que no reconocemos **se descarta sin error**
     * (SP-3): no es un `422` ni un log, porque quien la lleva no puede hacer
     * nada al respecto y la página tiene que abrir igual.
     */
    private function currencyDisplay(Request $request): CurrencyDisplay
    {
        $chosen = CurrencyDisplay::tryFromValue($request->cookie(CurrencyDisplay::COOKIE));

        if ($chosen instanceof CurrencyDisplay) {
            return $chosen;
        }

        if (! $this->context->hasOrganization()) {
            return CurrencyDisplay::platformDefault();
        }

        return CurrencyDisplay::tryFromValue($this->settings->get(SettingKey::CurrencyDisplayDefault))
            ?? CurrencyDisplay::platformDefault();
    }

    /**
     * La zona en la que el sello escribe su hora: la del negocio.
     *
     * Fuera de un tenant —marketing, acceso, feed— no hay negocio del que
     * tomarla y manda la de la aplicación.
     */
    private function timezone(): string
    {
        return $this->context->hasOrganization()
            ? $this->context->organization()->timezone
            : (string) config('app.timezone');
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
            'pendingReceipts' => fn (): ?int => $this->pendingReceipts($user),
            'organization' => [
                'name' => $organization->name,
                'slug' => $organization->slug,
                'status' => $organization->status->value,
            ],
        ];
    }

    /**
     * Cuántos comprobantes esperan al equipo: el badge de la bandeja
     * (`REQ-PAY-004` RB-3, SP-1). Viaja en cada navegación de Inertia, que es
     * la mitad del respaldo de SP-1; la otra mitad es el sondeo de 30 s.
     *
     * **Solo con el tenant del propio usuario fijado**, y por eso es una
     * *closure* que Inertia resuelve al responder, con la ruta ya resuelta: un
     * miembro del equipo con la sesión abierta puede estar mirando el
     * catálogo público **de otro negocio**, con el tenant de ese negocio
     * puesto, y contar ahí sería enseñarle la cola ajena. Fuera de su negocio
     * —o sin la capacidad de revisar comprobantes— es `null`, y el menú no
     * pinta cifra.
     */
    private function pendingReceipts(User $user): ?int
    {
        if (! $this->context->hasOrganization() || $this->context->organizationId() !== (int) $user->organization_id) {
            return null;
        }

        // Desactivado, ni cifra: su sesión puede seguir viva en el catálogo
        // de su propio negocio —desactivar no la cierra, y `admin-active` solo
        // vigila el backoffice—, y el canal de la bandeja ya lo rechaza.
        if ($user->disabled_at !== null || ! $user->role->can(OrganizationCapability::Receipts)) {
            return null;
        }

        return Receipt::query()->pendingReview()->count();
    }

    /**
     * La sesión del portal, si la hay: `PortalSessionView` (`REQ-IDN-004`).
     *
     * Solo lo que la cabecera del portal pinta —el modo, el nombre y el
     * negocio—, y **nunca** el `id` de la ficha ni su `account_id` (RB-4). La
     * sesión acotada es de un negocio, y lo dice: la persona tiene que saber
     * con quién está viendo sus cuentas.
     *
     * @return array{mode: 'scoped', fullName: string, businessName: string}|null
     */
    private function portalSession(): ?array
    {
        $customer = Auth::guard('customer')->user();

        if (! $customer instanceof Customer) {
            return null;
        }

        return [
            'mode' => 'scoped',
            'fullName' => $customer->full_name,
            'businessName' => (string) Organization::query()->whereKey($customer->organization_id)->value('name'),
        ];
    }
}
