<?php

declare(strict_types=1);

namespace Tests\Feature\Exchange;

use App\Actions\Settings\UpdateExchangeRateSettingsAction;
use App\Domain\Exchange\RateSource;
use App\Domain\Settings\SettingKey;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class UpdateExchangeRateSettingsActionTest extends TestCase
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

    public function test_it_saves_the_chosen_source(): void
    {
        $this->changeSource(RateSource::Parallel, null);

        $this->assertSame('parallel', $this->settingOf(SettingKey::ExchangeRateSource));
    }

    public function test_it_refuses_manual_without_a_rate(): void
    {
        try {
            $this->changeSource(RateSource::Manual, null);
            $this->fail('Se esperaba que exigiera la tasa manual.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(SettingKey::ExchangeRateManual->value, $exception->errors());
        }

        $this->assertSame('bcv', $this->settingOf(SettingKey::ExchangeRateSource));
    }

    public function test_it_saves_the_pair_together(): void
    {
        $this->changeSource(RateSource::Manual, '55.00');

        $this->assertSame('manual', $this->settingOf(SettingKey::ExchangeRateSource));
        $this->assertSame('55.00', $this->settingOf(SettingKey::ExchangeRateManual));
    }

    public function test_it_keeps_the_stored_manual_rate_when_none_is_submitted(): void
    {
        $this->changeSource(RateSource::Manual, '55.00');
        $this->changeSource(RateSource::Bcv, null);

        $this->assertSame('bcv', $this->settingOf(SettingKey::ExchangeRateSource));
        $this->assertSame('55.00', $this->settingOf(SettingKey::ExchangeRateManual));
    }

    public function test_it_stores_a_manual_rate_submitted_while_on_another_source(): void
    {
        $this->changeSource(RateSource::Bcv, '48.00');

        $this->assertSame('bcv', $this->settingOf(SettingKey::ExchangeRateSource));
        $this->assertSame('48.00', $this->settingOf(SettingKey::ExchangeRateManual));
    }

    public function test_it_refuses_a_rate_that_is_not_positive(): void
    {
        $this->expectException(ValidationException::class);

        $this->changeSource(RateSource::Manual, '0');
    }

    public function test_a_member_cannot_run_it(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->expectException(AuthorizationException::class);

        $this->withinOrganization(
            $this->organization,
            fn (): array => app(UpdateExchangeRateSettingsAction::class)->handle(RateSource::Parallel, null, $member),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function changeSource(RateSource $source, ?string $manualRate): array
    {
        $owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());

        return $this->withinOrganization(
            $this->organization,
            fn (): array => app(UpdateExchangeRateSettingsAction::class)->handle($source, $manualRate, $owner),
        );
    }

    private function settingOf(SettingKey $key): mixed
    {
        app(Settings::class)->forget($this->organization);

        return $this->withinOrganization($this->organization, fn (): mixed => app(Settings::class)->get($key));
    }
}
