<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Organization\UpdateOrganizationAction;
use App\Actions\Settings\UpdateExchangeRateSettingsAction;
use App\Actions\Settings\UpdateSettingsAction;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\RateSource;
use App\Domain\Exchange\RateView;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\SlugLockedException;
use App\Domain\Tenancy\SlugTakenException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\UpdateExchangeRateRequest;
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
        private readonly ExchangeRateService $rates,
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
            'rateSources' => $this->rateSources(),
            'rateSourceOptions' => $this->rateSourceOptions(),
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
     * Guarda la fuente de la tasa y, cuando toca, la tasa manual
     * (`REQ-FX-003` RB-1).
     *
     * Va aparte de `update()` porque es una operación distinta —un par que
     * solo tiene sentido guardado junto— con su propia pantalla y sus propios
     * datos. La capacidad es la misma, `Settings`, así que la policy también:
     * la tasa es uno de los ajustes que RB-1 de `REQ-ORG-003` reserva al
     * `owner`, y `OrganizationCapability::Settings` la nombra explícitamente.
     */
    public function updateRate(UpdateExchangeRateRequest $request, UpdateExchangeRateSettingsAction $update): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $update->handle($request->source(), $request->manualRate(), $actor);

        return to_route('settings.edit')->with('status', 'Fuente de la tasa guardada.');
    }

    /**
     * Las fuentes de plataforma con su tasa vigente y su antigüedad (RB-6).
     *
     * Es lo que permite al `owner` **comparar antes de elegir**: «BCV
     * Bs. 42,50 (hace 12 min) · Paralelo Bs. 47,80 (hace 12 min)». Sin esto, la
     * elección sería a ciegas entre tres palabras.
     *
     * La antigüedad se calcula en la pantalla sobre `publishedAt` —cuándo lo
     * publicó la fuente—, que es la misma fecha que viaja en `RateView` desde
     * que `T-019` corrigió el contrato: decir «hace 12 min» sobre la hora a la
     * que corrió nuestro job sería contar otra cosa.
     *
     * Una fuente **sin ninguna tasa todavía** viaja igual, con `rate: null`
     * (SP-4): se puede elegir, y la pantalla dice que aún no hay cifra en vez
     * de esconder la opción. Bloquear la elección por falta de tasa sería
     * violar `REQ-FX-005` RB-1 desde la pantalla de ajustes.
     *
     * @return list<array<string, mixed>>
     */
    private function rateSources(): array
    {
        return array_map(
            fn (RateSource $source): array => RateView::of($this->rates->forSource($source), $source)->toArray(),
            RateSource::platform(),
        );
    }

    /**
     * Las tres fuentes elegibles, con su nombre.
     *
     * **El nombre sale del enum** (`RateSource::label()`) y no de una
     * constante en el `.tsx`. Hasta `T-022` el desplegable guardaba las tres
     * etiquetas por su cuenta; con `RateStamp` escribiendo «Tasa BCV del …»
     * sobre las mismas tres, dos diccionarios acabarían llamando cosas
     * distintas a la misma tasa en dos pantallas — delante de alguien que ya
     * dudaba de la cifra.
     *
     * Aquí van **las tres**, incluida la manual, porque son las que se pueden
     * elegir; `rateSources()` son solo las de plataforma, porque son las que
     * tienen una cifra que comparar.
     *
     * @return list<array{value: string, label: string}>
     */
    private function rateSourceOptions(): array
    {
        return array_map(
            fn (RateSource $source): array => ['value' => $source->value, 'label' => $source->label()],
            RateSource::cases(),
        );
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
