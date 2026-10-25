<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Support\ExplodingCacheStore;
use Tests\TestCase;

final class SettingsCacheLeakTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ferreteria;

    private Organization $panaderia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ferreteria = $this->seedOrganizationWith(gracePeriodDays: 3);
        $this->panaderia = $this->seedOrganizationWith(gracePeriodDays: 14);

        Route::middleware(['web', 'auth', 'tenant', 'admin-active', 'organization-active', 'password-changed'])
            ->get('__settings/gracia', fn (): array => [
                'grace' => app(Settings::class)->get(SettingKey::GracePeriodDays),
            ]);
    }

    public function test_two_organizations_read_their_own_settings_in_the_same_request(): void
    {
        $settings = $this->settings();

        $this->assertSame(3, $settings->forOrganization($this->ferreteria)[SettingKey::GracePeriodDays->value]);
        $this->assertSame(14, $settings->forOrganization($this->panaderia)[SettingKey::GracePeriodDays->value]);
        $this->assertSame(3, $settings->forOrganization($this->ferreteria)[SettingKey::GracePeriodDays->value]);
    }

    public function test_two_organizations_read_their_own_settings_in_consecutive_requests(): void
    {
        $first = $this->ownerOf($this->ferreteria);
        $second = $this->ownerOf($this->panaderia);

        $this->actingAs($first)
            ->getJson('/__settings/gracia')
            ->assertOk()
            ->assertJson(['grace' => 3]);

        $this->nextRequest();

        $this->actingAs($second)
            ->getJson('/__settings/gracia')
            ->assertOk()
            ->assertJson(['grace' => 14]);

        $this->nextRequest();

        $this->actingAs($first)
            ->getJson('/__settings/gracia')
            ->assertOk()
            ->assertJson(['grace' => 3]);
    }

    private function nextRequest(): void
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->make(TenantContext::class)->forget();
    }

    public function test_each_organization_gets_its_own_cache_key(): void
    {
        $settings = $this->settings();

        $settings->forOrganization($this->ferreteria);
        $settings->forOrganization($this->panaderia);

        $ours = Settings::cacheKeyFor((int) $this->ferreteria->getKey());
        $theirs = Settings::cacheKeyFor((int) $this->panaderia->getKey());

        $this->assertNotSame($ours, $theirs);
        $this->assertTrue(Cache::has($ours));
        $this->assertTrue(Cache::has($theirs));

        $this->assertSame(3, Cache::get($ours)[SettingKey::GracePeriodDays->value]);
        $this->assertSame(14, Cache::get($theirs)[SettingKey::GracePeriodDays->value]);
    }

    public function test_poisoning_one_organizations_entry_does_not_reach_the_other(): void
    {
        $settings = $this->settings();

        Cache::put(
            Settings::cacheKeyFor((int) $this->ferreteria->getKey()),
            [SettingKey::GracePeriodDays->value => 999],
            60,
        );

        $this->assertSame(14, $settings->forOrganization($this->panaderia)[SettingKey::GracePeriodDays->value]);

        $this->assertSame(999, $settings->forOrganization($this->ferreteria)[SettingKey::GracePeriodDays->value]);
    }

    public function test_forgetting_one_organization_leaves_the_other_cached(): void
    {
        $settings = $this->settings();

        $settings->forOrganization($this->ferreteria);
        $settings->forOrganization($this->panaderia);

        $settings->forget($this->ferreteria);

        $this->assertFalse(Cache::has(Settings::cacheKeyFor((int) $this->ferreteria->getKey())));
        $this->assertTrue(Cache::has(Settings::cacheKeyFor((int) $this->panaderia->getKey())));
    }

    public function test_a_broken_cache_falls_back_to_the_database(): void
    {
        $store = $this->useExplodingCache();

        $settings = $this->settings();

        $this->assertSame(3, $settings->forOrganization($this->ferreteria)[SettingKey::GracePeriodDays->value]);
        $this->assertSame(14, $settings->forOrganization($this->panaderia)[SettingKey::GracePeriodDays->value]);

        $this->assertGreaterThan(0, $store->attempts, 'No se llegó a tocar la caché.');
    }

    public function test_forgetting_survives_a_broken_cache(): void
    {
        $this->useExplodingCache();

        $settings = $this->settings();

        $settings->forget($this->ferreteria);

        $this->assertSame(3, $settings->forOrganization($this->ferreteria)[SettingKey::GracePeriodDays->value]);
    }

    public function test_a_written_setting_is_visible_right_after(): void
    {
        $settings = $this->settings();

        $this->assertSame(3, $settings->forOrganization($this->ferreteria)[SettingKey::GracePeriodDays->value]);

        $this->withinOrganization($this->ferreteria, function (): void {
            OrganizationSetting::query()
                ->where('key', SettingKey::GracePeriodDays)
                ->update(['value' => json_encode(9, JSON_THROW_ON_ERROR)]);
        });

        $settings->forget($this->ferreteria);

        $this->assertSame(9, $settings->forOrganization($this->ferreteria)[SettingKey::GracePeriodDays->value]);
    }

    private function settings(): Settings
    {
        return $this->app->make(Settings::class);
    }

    private function useExplodingCache(): ExplodingCacheStore
    {
        $store = new ExplodingCacheStore;

        Cache::extend('exploding', fn () => Cache::repository($store));

        config([
            'cache.stores.exploding' => ['driver' => 'exploding'],
            'cache.default' => 'exploding',
        ]);

        $this->app->forgetInstance(Settings::class);

        $this->assertThrows(fn () => Cache::get('lo que sea'), RuntimeException::class);

        return $store;
    }

    private function seedOrganizationWith(int $gracePeriodDays): Organization
    {
        $organization = Organization::factory()->create();

        $this->withinOrganization($organization, function () use ($gracePeriodDays): void {
            foreach (SettingKey::cases() as $key) {
                OrganizationSetting::query()->create([
                    'key' => $key,
                    'value' => $key === SettingKey::GracePeriodDays ? $gracePeriodDays : $key->defaultValue(),
                    'updated_by' => null,
                ]);
            }
        });

        $this->withoutOrganization();

        return $organization;
    }

    private function ownerOf(Organization $organization): User
    {
        $owner = $this->withinOrganization($organization, fn (): User => User::factory()->owner()->create());

        $this->withoutOrganization();

        return $owner;
    }
}
