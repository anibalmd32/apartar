<?php

declare(strict_types=1);

namespace Tests\Feature\Exchange;

use App\Domain\Exchange\RateSource;
use App\Domain\Money\CurrencyDisplay;
use App\Domain\Settings\SettingKey;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class CurrencyPagePropsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['timezone' => 'America/Caracas']);

        $plan = Plan::factory()->create();

        $this->withinOrganization($this->organization, function () use ($plan): void {
            Subscription::factory()->forPlan($plan)->create();

            foreach (SettingKey::cases() as $key) {
                OrganizationSetting::query()->create([
                    'key' => $key,
                    'value' => $key->defaultValue(),
                    'updated_by' => null,
                ]);
            }
        });

        $this->withoutOrganization();
    }

    public function test_without_a_cookie_the_organization_default_wins(): void
    {
        $this->setDisplayDefault(CurrencyDisplay::VesPrimary);

        $this->actingAs($this->owner())
            ->get('/backoffice/ajustes')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('currency.display', 'ves_primary'));
    }

    public function test_a_valid_cookie_wins_over_the_organization_default(): void
    {
        $this->setDisplayDefault(CurrencyDisplay::VesPrimary);

        $this->actingAs($this->owner())
            ->withUnencryptedCookie(CurrencyDisplay::COOKIE, 'usd_primary')
            ->get('/backoffice/ajustes')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('currency.display', 'usd_primary'));
    }

    public function test_a_corrupt_cookie_falls_back_without_an_error(): void
    {
        $this->setDisplayDefault(CurrencyDisplay::VesPrimary);

        $this->actingAs($this->owner())
            ->withUnencryptedCookie(CurrencyDisplay::COOKIE, 'bolivares-por-favor')
            ->get('/backoffice/ajustes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('currency.display', 'ves_primary'));
    }

    public function test_an_empty_cookie_falls_back_too(): void
    {
        $this->actingAs($this->owner())
            ->withUnencryptedCookie(CurrencyDisplay::COOKIE, '')
            ->get('/backoffice/ajustes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('currency.display', 'usd_primary'));
    }

    public function test_the_preference_survives_changing_organization(): void
    {
        $other = Organization::factory()->create();

        $plan = Plan::factory()->create();

        $this->withinOrganization($other, function () use ($plan): void {
            Subscription::factory()->forPlan($plan)->create();

            OrganizationSetting::query()->create([
                'key' => SettingKey::CurrencyDisplayDefault,
                'value' => CurrencyDisplay::UsdPrimary->value,
                'updated_by' => null,
            ]);
        });

        $otherOwner = $this->withinOrganization($other, fn (): User => User::factory()->owner()->create());

        $this->actingAs($otherOwner)
            ->withUnencryptedCookie(CurrencyDisplay::COOKIE, 'ves_primary')
            ->get('/backoffice/ajustes')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('currency.display', 'ves_primary'));
    }

    public function test_outside_a_tenant_the_platform_default_wins(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('currency.display', 'usd_primary')
                ->where('currency.rate.rate', null));
    }

    public function test_the_rate_travels_with_its_source_label(): void
    {
        $this->persistRate('42.50000000');

        $this->actingAs($this->owner())
            ->get('/backoffice/ajustes')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('currency.rate.rate', 42.5)
                ->where('currency.rate.source', 'bcv')
                ->where('currency.rate.sourceLabel', 'BCV')
                ->where('currency.rate.isStale', false)
                ->has('currency.rate.publishedAt'));
    }

    public function test_without_a_rate_the_prop_is_null(): void
    {
        $this->actingAs($this->owner())
            ->get('/backoffice/ajustes')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('currency.rate.rate', null)
                ->where('currency.rate.publishedAt', null)
                ->where('currency.rate.isStale', false));
    }

    public function test_the_timezone_is_the_organizations(): void
    {
        $this->organization->forceFill(['timezone' => 'America/Bogota'])->save();

        $this->actingAs($this->owner())
            ->get('/backoffice/ajustes')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('currency.timezone', 'America/Bogota'));
    }

    public function test_outside_a_tenant_the_timezone_is_the_applications(): void
    {
        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('currency.timezone', config('app.timezone')));
    }

    private function owner(): User
    {
        return $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
    }

    private function setDisplayDefault(CurrencyDisplay $display): void
    {
        $this->withinOrganization($this->organization, function () use ($display): void {
            OrganizationSetting::query()->updateOrCreate(
                ['key' => SettingKey::CurrencyDisplayDefault->value],
                ['value' => $display->value],
            );
        });

        app(\App\Support\Settings::class)->forget($this->organization);
    }

    private function persistRate(string $rate): void
    {
        ExchangeRate::query()->create([
            'source' => RateSource::Bcv,
            'rate' => $rate,
            'published_at' => CarbonImmutable::now()->subHour(),
        ]);

        Cache::flush();
    }
}
