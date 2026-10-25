<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Actions\Settings\UpdateSettingsAction;
use App\Domain\Settings\SettingKey;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class UpdateSettingsActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        foreach ([$neighbour, $this->organization] as $organization) {
            $this->seedSettings($organization);
        }

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->actingAsOrganization($this->organization);
    }

    public function test_the_owner_saves_a_setting(): void
    {
        $owner = User::factory()->owner()->create();

        $resolved = $this->action()->handle(['grace_period_days' => 7], $owner);

        $this->assertSame(7, $resolved['grace_period_days']);
        $this->assertSame(7, $this->settings()->get(SettingKey::GracePeriodDays));
    }

    public function test_it_records_who_changed_it(): void
    {
        $owner = User::factory()->owner()->create();

        $this->action()->handle(['grace_period_days' => 7], $owner);

        $row = OrganizationSetting::query()->where('key', SettingKey::GracePeriodDays)->firstOrFail();

        $this->assertSame($owner->getKey(), $row->updated_by);
    }

    public function test_a_member_cannot_save_settings(): void
    {
        $member = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        $this->action()->handle(['grace_period_days' => 7], $member);
    }

    public function test_an_unknown_key_is_ignored_without_error(): void
    {
        $owner = User::factory()->owner()->create();

        $resolved = $this->action()->handle([
            'grace_period_days' => 7,
            'storefront_enabled' => true,
            'lo_que_sea' => 'da igual',
        ], $owner);

        $this->assertSame(7, $resolved['grace_period_days']);
        $this->assertArrayNotHasKey('storefront_enabled', $resolved);
        $this->assertArrayNotHasKey('lo_que_sea', $resolved);

        $this->assertDatabaseMissing('organization_settings', [
            'organization_id' => $this->organization->getKey(),
            'key' => 'storefront_enabled',
        ]);
    }

    public function test_a_payload_of_only_unknown_keys_writes_nothing(): void
    {
        $owner = User::factory()->owner()->create();

        $resolved = $this->action()->handle(['lo_que_sea' => 1], $owner);

        $this->assertSame(3, $resolved['grace_period_days']);
        $this->assertSame(10, OrganizationSetting::query()->count());
    }

    public function test_a_value_out_of_range_is_rejected(): void
    {
        $owner = User::factory()->owner()->create();

        try {
            $this->action()->handle(['default_frequency_days' => 900], $owner);
            $this->fail('Un valor fuera de rango debió rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('default_frequency_days', $exception->errors());
        }

        $this->assertSame(15, $this->settings()->get(SettingKey::DefaultFrequencyDays));
    }

    public function test_choosing_the_manual_source_requires_its_rate(): void
    {
        $owner = User::factory()->owner()->create();

        try {
            $this->action()->handle(['exchange_rate_source' => 'manual'], $owner);
            $this->fail('La fuente manual sin tasa debió rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('exchange_rate_manual', $exception->errors());
        }

        $this->action()->handle([
            'exchange_rate_source' => 'manual',
            'exchange_rate_manual' => '42.50',
        ], $owner);

        $this->assertSame('manual', $this->settings()->get(SettingKey::ExchangeRateSource));
    }

    public function test_an_already_stored_manual_rate_is_enough(): void
    {
        $owner = User::factory()->owner()->create();

        $this->action()->handle(['exchange_rate_manual' => '42.50'], $owner);
        $this->action()->handle(['exchange_rate_source' => 'manual'], $owner);

        $this->assertSame('manual', $this->settings()->get(SettingKey::ExchangeRateSource));
    }

    public function test_saving_invalidates_the_cache(): void
    {
        $owner = User::factory()->owner()->create();

        $this->settings()->get(SettingKey::GracePeriodDays);

        Cache::put(Settings::cacheKeyFor((int) $this->organization->getKey()), ['grace_period_days' => 999], 60);

        $this->action()->handle(['grace_period_days' => 7], $owner);

        $this->assertSame(7, $this->settings()->get(SettingKey::GracePeriodDays));
        $this->assertSame(7, Cache::get(Settings::cacheKeyFor((int) $this->organization->getKey()))['grace_period_days']);
    }

    public function test_saving_does_not_reach_the_neighbour(): void
    {
        $owner = User::factory()->owner()->create();

        $this->action()->handle(['grace_period_days' => 7], $owner);

        $theirs = $this->settings()->forOrganization($this->neighbourOrganization());

        $this->assertSame(3, $theirs['grace_period_days']);
    }

    public function test_saving_touches_only_its_own_row(): void
    {
        $owner = User::factory()->owner()->create();

        $before = OrganizationSetting::query()->pluck('value', 'key')->all();

        $this->action()->handle(['grace_period_days' => 7], $owner);

        $after = OrganizationSetting::query()->pluck('value', 'key')->all();

        unset($before['grace_period_days'], $after['grace_period_days']);

        $this->assertSame($before, $after);
    }

    private function action(): UpdateSettingsAction
    {
        return app(UpdateSettingsAction::class);
    }

    private function settings(): Settings
    {
        return app(Settings::class);
    }

    private function seedSettings(Organization $organization): void
    {
        $this->withinOrganization($organization, function (): void {
            foreach (SettingKey::cases() as $key) {
                OrganizationSetting::query()->create([
                    'key' => $key,
                    'value' => $key->defaultValue(),
                    'updated_by' => null,
                ]);
            }
        });
    }
}
