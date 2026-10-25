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

    public function update(UpdateSettingsRequest $request, UpdateSettingsAction $update): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $update->handle($request->settings(), $actor);

        return to_route('settings.edit')->with('status', 'Ajustes guardados.');
    }

    /**
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

    public function updateRate(UpdateExchangeRateRequest $request, UpdateExchangeRateSettingsAction $update): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $update->handle($request->source(), $request->manualRate(), $actor);

        return to_route('settings.edit')->with('status', 'Fuente de la tasa guardada.');
    }

    /**
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
            'tagline' => $attributes['tagline'] ?? null,
            'description' => $attributes['description'] ?? null,
            'address' => $attributes['address'] ?? null,
        ];
    }
}
