<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Settings\SettingKey;
use App\Models\OrganizationSetting;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Ajustes sueltos para los tests.
 *
 * El escenario normal no pasa por aquí: los once ajustes los siembra
 * `RegisterOrganizationAction` con sus valores por defecto. Esta factory sirve
 * para los casos que necesitan un ajuste concreto con un valor concreto.
 *
 * @extends Factory<OrganizationSetting>
 */
class OrganizationSettingFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<OrganizationSetting> */
    protected $model = OrganizationSetting::class;

    /**
     * @return array<model-property<OrganizationSetting>, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => SettingKey::GracePeriodDays,
            'value' => SettingKey::GracePeriodDays->defaultValue(),
            'updated_by' => null,
        ];
    }

    public function forKey(SettingKey $key, mixed $value = null): static
    {
        return $this->state(fn (): array => [
            'key' => $key,
            'value' => $value ?? $key->defaultValue(),
        ]);
    }
}
