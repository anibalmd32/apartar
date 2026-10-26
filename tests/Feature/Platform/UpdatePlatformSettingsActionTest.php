<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Platform\PlatformSettingKey;
use App\Models\PlatformActivity;
use App\Support\Auditing\PlatformAudit;
use App\Support\PlatformSettings;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class UpdatePlatformSettingsActionTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReusesMigratedDatabase;

    public function test_it_shows_every_setting_with_its_value_default_and_range(): void
    {
        PlatformSettings::set(PlatformSettingKey::SubscriptionGraceDays, 10);
        $this->asOperator();

        $this->get('/plataforma/ajustes')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('platform/settings/Index')
            ->has('settings', count(PlatformSettingKey::cases()))
            ->where('settings.1.key', PlatformSettingKey::SubscriptionGraceDays->value)
            ->where('settings.1.value', 10)
            ->where('settings.1.default', 7)
            ->where('settings.1.min', 1)
            ->where('settings.1.max', 60)
            ->where('temporaryPassword', null));
    }

    public function test_saving_changes_only_what_differs_and_audits_each_change(): void
    {
        $this->asOperator();

        $this->put('/plataforma/ajustes', ['settings' => $this->values([PlatformSettingKey::SubscriptionGraceDays->value => 12])])
            ->assertRedirect()
            ->assertSessionHas('status', 'Ajustes guardados.');

        $this->assertSame(12, PlatformSettings::get(PlatformSettingKey::SubscriptionGraceDays));
        $entries = PlatformActivity::query()->where('action', PlatformAudit::SETTING_UPDATED)->get();
        $this->assertCount(1, $entries);
        $this->assertEquals(['key' => 'subscription_grace_days', 'before' => 7, 'after' => 12], $entries->first()?->properties);

        $this->put('/plataforma/ajustes', ['settings' => $this->values([PlatformSettingKey::SubscriptionGraceDays->value => 12])])
            ->assertSessionHas('status', 'No había cambios que guardar.');
        $this->assertSame(1, PlatformActivity::query()->where('action', PlatformAudit::SETTING_UPDATED)->count());
    }

    public function test_a_value_out_of_range_is_rejected_and_nothing_is_saved(): void
    {
        $this->asOperator();

        $this->put('/plataforma/ajustes', ['settings' => $this->values([
            PlatformSettingKey::SubscriptionGraceDays->value => 20,
            PlatformSettingKey::SupportAccessTtlHours->value => 500,
        ])])->assertSessionHasErrors('settings.support_access_ttl_hours');

        $this->assertSame(7, PlatformSettings::get(PlatformSettingKey::SubscriptionGraceDays));
    }

    /**
     * @param  array<string, int>  $overrides
     * @return array<string, int>
     */
    private function values(array $overrides): array
    {
        $values = [];

        foreach (PlatformSettingKey::cases() as $key) {
            $values[$key->value] = PlatformSettings::get($key);
        }

        return array_replace($values, $overrides);
    }
}
