<?php

declare(strict_types=1);

namespace Tests\Feature\Exchange;

use App\Domain\Exchange\CurrentRate;
use App\Domain\Exchange\RateSource;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CurrentRateTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();

        $this->withinOrganization($this->organization, function (): void {
            foreach (SettingKey::cases() as $key) {
                OrganizationSetting::query()->create([
                    'key' => $key,
                    'value' => $key->defaultValue(),
                    'updated_by' => null,
                ]);
            }
        });
    }

    public function test_it_resolves_the_rate_of_the_active_organization(): void
    {
        $this->persistRate('42.50000000');
        app(TenantContext::class)->setOrganization($this->organization);

        $this->assertSame('42.50000000', app(CurrentRate::class)->snapshot()?->rate);
    }

    public function test_it_resolves_only_once(): void
    {
        $this->persistRate('42.50000000');
        app(TenantContext::class)->setOrganization($this->organization);

        $rate = app(CurrentRate::class);
        $first = $rate->snapshot()?->rate;

        $this->persistRate('90.00000000', CarbonImmutable::now());

        $this->assertSame($first, $rate->snapshot()?->rate);
        $this->assertSame(42.5, $rate->view()->rate);
    }

    public function test_the_view_comes_from_the_same_snapshot(): void
    {
        $this->persistRate('42.50000000');
        app(TenantContext::class)->setOrganization($this->organization);

        $rate = app(CurrentRate::class);

        $this->assertSame(42.5, $rate->view()->rate);
        $this->assertSame('42.50000000', $rate->snapshot()?->rate);
    }

    public function test_without_any_rate_it_answers_null_without_failing(): void
    {
        app(TenantContext::class)->setOrganization($this->organization);

        $rate = app(CurrentRate::class);

        $this->assertNull($rate->snapshot());
        $this->assertNull($rate->view()->rate);
        $this->assertFalse($rate->view()->isStale);
    }

    public function test_outside_a_tenant_it_answers_with_the_platform_source(): void
    {
        app(TenantContext::class)->forget();

        $view = app(CurrentRate::class)->view();

        $this->assertSame(RateSource::Bcv, $view->source);
        $this->assertNull($view->rate);
    }

    public function test_it_uses_the_source_the_organization_chose(): void
    {
        $this->persistRate('36.50000000', source: RateSource::Bcv);
        $this->persistRate('42.20000000', source: RateSource::Parallel);

        $this->withinOrganization($this->organization, function (): void {
            OrganizationSetting::query()->updateOrCreate(
                ['key' => SettingKey::ExchangeRateSource->value],
                ['value' => RateSource::Parallel->value],
            );
        });

        app(\App\Support\Settings::class)->forget($this->organization);
        app(TenantContext::class)->setOrganization($this->organization);

        $this->assertSame('42.20000000', app(CurrentRate::class)->snapshot()?->rate);
        $this->assertSame(RateSource::Parallel, app(CurrentRate::class)->view()->source);
    }

    public function test_the_memo_dies_with_the_request(): void
    {
        $this->persistRate('42.50000000');
        app(TenantContext::class)->setOrganization($this->organization);

        $this->assertSame('42.50000000', app(CurrentRate::class)->snapshot()?->rate);

        $this->persistRate('50.00000000', CarbonImmutable::now());

        app()->forgetScopedInstances();

        $this->assertSame('50.00000000', app(CurrentRate::class)->snapshot()->rate);
    }

    private function persistRate(
        string $rate,
        ?CarbonImmutable $publishedAt = null,
        RateSource $source = RateSource::Bcv,
    ): void {
        ExchangeRate::query()->create([
            'source' => $source,
            'rate' => $rate,
            'published_at' => $publishedAt ?? CarbonImmutable::now()->subHour(),
        ]);

        Cache::flush();
    }
}
