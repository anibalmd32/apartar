<?php

declare(strict_types=1);

namespace Tests\Feature\Exchange;

use App\Domain\Exchange\ExchangeRateUnavailableException;
use App\Domain\Exchange\ManualRateProvider;
use App\Domain\Exchange\RateSource;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ManualRateProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reads_the_rate_the_business_wrote(): void
    {
        $organization = $this->organizationWithManualRate('55.00000000');

        $snapshot = app(TenantContext::class)->runFor(
            $organization,
            fn () => app(ManualRateProvider::class)->fetch(RateSource::Manual),
        );

        $this->assertSame('55.00000000', $snapshot->rate);
        $this->assertSame(RateSource::Manual, $snapshot->source);
    }

    public function test_it_never_calls_an_external_api(): void
    {
        Http::fake();

        $organization = $this->organizationWithManualRate('55.00000000');

        app(TenantContext::class)->runFor(
            $organization,
            fn () => app(ManualRateProvider::class)->fetch(RateSource::Manual),
        );

        Http::assertNothingSent();
    }

    public function test_it_dates_the_rate_with_its_last_edit(): void
    {
        $organization = $this->organizationWithManualRate('55.00000000');

        app(TenantContext::class)->runFor($organization, function (): void {
            OrganizationSetting::query()
                ->where('key', SettingKey::ExchangeRateManual->value)
                ->update(['updated_at' => CarbonImmutable::now()->subDays(21)]);
        });

        $snapshot = app(TenantContext::class)->runFor(
            $organization,
            fn () => app(ManualRateProvider::class)->fetch(RateSource::Manual),
        );

        $this->assertTrue($snapshot->isStale());
        $this->assertTrue($snapshot->publishedAt->equalTo($snapshot->fetchedAt));
    }

    public function test_it_only_supports_the_manual_source(): void
    {
        $provider = app(ManualRateProvider::class);

        $this->assertTrue($provider->supports(RateSource::Manual));
        $this->assertFalse($provider->supports(RateSource::Bcv));
        $this->assertFalse($provider->supports(RateSource::Parallel));
    }

    public function test_it_refuses_a_public_source(): void
    {
        $this->expectException(ExchangeRateUnavailableException::class);

        app(ManualRateProvider::class)->fetch(RateSource::Bcv);
    }

    public function test_it_refuses_without_an_organization(): void
    {
        app(TenantContext::class)->forget();

        $this->expectException(ExchangeRateUnavailableException::class);
        $this->expectExceptionMessage('no hay ninguna fijada');

        app(ManualRateProvider::class)->fetch(RateSource::Manual);
    }

    public function test_it_refuses_when_no_rate_was_written(): void
    {
        $organization = $this->organizationWithManualRate(null);

        $this->expectException(ExchangeRateUnavailableException::class);
        $this->expectExceptionMessage('no ha escrito ninguna tasa');

        app(TenantContext::class)->runFor(
            $organization,
            fn () => app(ManualRateProvider::class)->fetch(RateSource::Manual),
        );
    }

    private function organizationWithManualRate(?string $rate): Organization
    {
        $organization = Organization::factory()->create();

        app(TenantContext::class)->runFor($organization, function () use ($rate): void {
            OrganizationSetting::query()->updateOrCreate(
                ['key' => SettingKey::ExchangeRateManual->value],
                ['value' => $rate],
            );
        });

        return $organization;
    }
}
