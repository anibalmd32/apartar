<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Exchange\RateSource;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Models\OrganizationSetting;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UpdateSettingsAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly TenantContext $context,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(array $values, User $actor): array
    {
        $organization = $this->context->organization();

        Gate::forUser($actor)->authorize('updateSettings', $organization);

        $known = $this->onlyKnownKeys($values);

        if ($known === []) {
            return $this->settings->all();
        }

        $validated = $this->validate($known);

        $before = $this->currentValuesOf(array_keys($validated));

        DB::transaction(function () use ($validated, $before, $actor): void {
            foreach ($validated as $key => $value) {
                $setting = SettingKey::fromKey((string) $key);

                OrganizationSetting::query()->updateOrCreate(
                    ['key' => $setting],
                    ['value' => $value, 'updated_by' => $actor->getKey()],
                );
            }

            $this->audit(
                AuditEvent::SettingsUpdated,
                actor: $actor,
                before: $before,
                after: $validated,
            );
        });

        $this->settings->forget($organization);

        return $this->settings->all();
    }

    /**
     * @param  list<array-key>  $keys
     * @return array<string, mixed>
     */
    private function currentValuesOf(array $keys): array
    {
        $current = [];

        foreach ($keys as $key) {
            $current[(string) $key] = $this->settings->get(SettingKey::fromKey((string) $key));
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function onlyKnownKeys(array $values): array
    {
        $known = [];

        foreach ($values as $key => $value) {
            if (SettingKey::tryFromKey((string) $key) instanceof SettingKey) {
                $known[(string) $key] = $value;
            }
        }

        return $known;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(array $values): array
    {
        $rules = [];

        foreach (array_keys($values) as $key) {
            $setting = SettingKey::fromKey((string) $key);

            $rules[$setting->value] = $setting->validationRules();

            $items = $setting->itemValidationRules();

            if ($items !== null) {
                $rules[$setting->value.'.*'] = $items;
            }
        }

        $validator = Validator::make($values, $rules);

        $validator->after(function ($validator) use ($values): void {
            $this->assertManualSourceCarriesItsRate($validator, $values);
        });

        /** @var array<string, mixed> $validated */
        $validated = $validator->validate();

        return $validated;
    }

    /**
     * @param  \Illuminate\Validation\Validator  $validator
     * @param  array<string, mixed>  $values
     */
    private function assertManualSourceCarriesItsRate($validator, array $values): void
    {
        $sourceKey = SettingKey::ExchangeRateSource->value;
        $rateKey = SettingKey::ExchangeRateManual->value;

        if (($values[$sourceKey] ?? null) !== RateSource::Manual->value) {
            return;
        }

        $rate = $values[$rateKey] ?? $this->settings->get(SettingKey::ExchangeRateManual);

        if ($rate === null || $rate === '') {
            $validator->errors()->add(
                $rateKey,
                'Para usar una tasa manual tienes que escribirla en esta misma operación.',
            );
        }
    }
}
