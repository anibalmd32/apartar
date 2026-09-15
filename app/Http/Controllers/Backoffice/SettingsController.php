<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Organization\UpdateOrganizationAction;
use App\Actions\Settings\UpdateSettingsAction;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\SlugLockedException;
use App\Domain\Tenancy\SlugTakenException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\UpdateOrganizationRequest;
use App\Http\Requests\Backoffice\UpdateSettingsRequest;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ajustes del negocio: cómo opera y cómo se ve (`REQ-ORG-006`).
 *
 * **Una pantalla y dos guardados**, y la separación no es estética: los
 * ajustes de operación y la identidad son dos capacidades distintas de
 * `REQ-ORG-003` RB-1 —`Settings` y `Branding`— con dos policies distintas y
 * dos alcances distintos. Juntarlos en un `PUT` obligaría a autorizar por la
 * más permisiva de las dos y a revalidar todo para cambiar un color.
 *
 * Lo que sí comparten es la pantalla, porque para quien la usa es un solo
 * sitio: «la configuración de mi negocio».
 *
 * El alcance de cada clave viaja con ella (RB-4). No es adorno: quien cambia
 * la ventana del abono inicial tiene que saber **antes de guardar** que no va
 * a mover los apartados que ya tiene en curso, y esa frase sale de
 * `SettingKey::scope()` y no de un párrafo escrito en el `.tsx`, para que no
 * pueda decir una cosa distinta de la que hace el cálculo.
 */
final class SettingsController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Settings $settings,
        private readonly UpdateOrganizationAction $organization,
    ) {}

    public function edit(Request $request): Response
    {
        $organization = $this->context->organization();

        Gate::forUser($request->user())->authorize('viewSettings', $organization);

        return Inertia::render('backoffice/settings/Index', [
            'settings' => $this->settings->all(),
            'definitions' => $this->definitions(),
            'identity' => $this->identity($organization->getAttributes()),
            'brand' => $this->organization->brandOf($organization)->toArray(),
        ]);
    }

    /**
     * Guarda los ajustes de operación (RB-2, RB-3, RB-4).
     */
    public function update(UpdateSettingsRequest $request, UpdateSettingsAction $update): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $update->handle($request->settings(), $actor);

        return to_route('settings.edit')->with('status', 'Ajustes guardados.');
    }

    /**
     * Guarda la identidad: nombre, enlace, contacto, zona y marca.
     *
     * Los dos fallos del enlace se traducen a errores de campo porque es donde
     * la persona los puede corregir, y llevan mensajes distintos a propósito:
     * «ese enlace es de otro» tiene salida —una variante libre— y «el tuyo ya
     * está en la calle» no la tiene (SP-5).
     *
     * @throws ValidationException
     */
    public function updateIdentity(UpdateOrganizationRequest $request, UpdateOrganizationAction $update): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $update->handle($request->toData(), $actor);
        } catch (SlugLockedException|SlugTakenException $exception) {
            throw ValidationException::withMessages(['slug' => $exception->getMessage()]);
        }

        return to_route('settings.edit')->with('status', 'Identidad del negocio guardada.');
    }

    /**
     * Qué es cada clave, para que la pantalla no lo invente.
     *
     * Nombre, rango y **alcance** salen del enum. La alternativa —repetirlos
     * en el `.tsx`— tendría el mismo problema que tenía la frontera de roles
     * antes de `T-014`: dos fuentes de verdad que coinciden hasta el día en
     * que una cambia.
     *
     * @return list<array<string, mixed>>
     */
    private function definitions(): array
    {
        return array_map(fn (SettingKey $key): array => [
            'key' => $key->value,
            'label' => $key->label(),
            'scope' => $key->scope()->value,
            'scopeNote' => $key->scope()->description(),
        ], SettingKey::cases());
    }

    /**
     * La identidad editable, tal como la espera el formulario.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function identity(array $attributes): array
    {
        return [
            'name' => $attributes['name'],
            'slug' => $attributes['slug'],
            'document' => $attributes['document'],
            'phone' => $attributes['phone'],
            'timezone' => $attributes['timezone'],
            'brandColor' => $attributes['brand_color'],
            'storefrontEnabled' => (bool) $attributes['storefront_enabled'],
        ];
    }
}
