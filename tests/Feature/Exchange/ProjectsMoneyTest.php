<?php

declare(strict_types=1);

namespace Tests\Feature\Exchange;

use App\Domain\Exchange\RateSource;
use App\Domain\Money\Money;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class ProjectsMoneyTest extends TestCase
{
    use ProjectsMoney;
    use RefreshDatabase;

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

        app(TenantContext::class)->setOrganization($this->organization);
    }

    public function test_it_composes_both_representations(): void
    {
        $this->persistRate('42.50000000');

        $this->assertSame(
            ['cents' => 2_000, 'usd' => '$20,00', 'ves' => 'Bs. 850,00'],
            $this->money(new Money(2_000)),
        );
    }

    public function test_it_keeps_the_punctuation_of_each_currency(): void
    {
        $this->persistRate('42.50000000');

        $view = $this->money(new Money(123_456));

        $this->assertSame('$1.234,56', $view['usd']);
        $this->assertSame('Bs. 52.468,80', $view['ves']);
    }

    public function test_without_a_rate_the_bolivar_does_not_appear(): void
    {
        $this->assertSame(0, ExchangeRate::query()->count());

        $view = $this->money(new Money(2_000));

        $this->assertNull($view['ves']);
        $this->assertSame('$20,00', $view['usd']);
        $this->assertSame(2_000, $view['cents']);
    }

    public function test_the_missing_bolivar_is_never_a_zero_or_a_dash(): void
    {
        $view = $this->money(new Money(2_000));

        $this->assertNotSame('Bs. 0,00', $view['ves']);
        $this->assertNotSame('—', $view['ves']);
        $this->assertNotSame('', $view['ves']);
    }

    public function test_a_stale_rate_still_projects(): void
    {
        $this->persistRate('42.50000000', CarbonImmutable::now()->subHours(30));

        $view = $this->money(new Money(2_000));

        $this->assertSame('Bs. 850,00', $view['ves']);
        $this->assertTrue(app(\App\Domain\Exchange\CurrentRate::class)->view()->isStale);
    }

    public function test_it_projects_with_the_source_the_organization_chose(): void
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

        $this->assertSame('Bs. 844,00', $this->money(new Money(2_000))['ves']);
    }

    public function test_every_amount_of_a_response_uses_the_same_rate(): void
    {
        $this->persistRate('42.50000000');

        $first = $this->money(new Money(2_000));

        $this->persistRate('90.00000000', CarbonImmutable::now()->addMinute());
        Cache::flush();

        $second = $this->money(new Money(2_000));

        $this->assertSame($first['ves'], $second['ves']);
        $this->assertSame('Bs. 850,00', $second['ves']);
    }

    public function test_the_stamp_and_the_amounts_come_from_the_same_resolution(): void
    {
        $this->persistRate('42.50000000');

        $view = $this->money(new Money(2_000));
        $stamp = app(\App\Domain\Exchange\CurrentRate::class)->view();

        $this->assertSame('Bs. 850,00', $view['ves']);
        $this->assertSame(42.5, $stamp->rate);
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
