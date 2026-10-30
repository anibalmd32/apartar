<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Actions\Layaway\OpenLayawayAction;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\LayawayTerms;
use App\Domain\Money\Money;
use App\Domain\Settings\SettingKey;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class OpenLayawayActionTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-27 10:00:00');

        $this->organization = Organization::factory()->create(['timezone' => 'America/Caracas']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_with_down_payment_the_window_comes_from_the_settings(): void
    {
        $terms = new LayawayTerms(new Money(20_000), new Money(5_000), 1, 5, 15);

        $this->withinOrganization($this->organization, fn () => OrganizationSetting::factory()->forKey(SettingKey::DownPaymentWindowHours, 24)->create());

        $short = $this->withinOrganization($this->organization, fn (): ?CarbonImmutable => app(OpenLayawayAction::class)->expiresAt($terms));
        $this->assertSame('2026-09-28 10:00:00', $short?->toDateTimeString());
    }

    public function test_without_down_payment_there_is_no_window(): void
    {
        $terms = new LayawayTerms(new Money(20_000), new Money(0), 2, 5, 15);

        $this->assertNull($this->withinOrganization($this->organization, fn (): ?CarbonImmutable => app(OpenLayawayAction::class)->expiresAt($terms)));
    }

    public function test_without_down_payment_it_activates_and_materializes_the_schedule(): void
    {
        $layaway = $this->layawayIn($this->organization, terms: new LayawayTerms(new Money(20_000), new Money(0), 1, 4, 15));

        $this->withinOrganization($this->organization, fn () => app(OpenLayawayAction::class)->handle($layaway));

        $this->assertSame(LayawayStatus::Active, $layaway->status);
        $this->assertSame('2026-09-27 10:00:00', $layaway->activated_at?->toDateTimeString());
        $this->assertSame(0, $layaway->paid_cents->cents);
        $this->assertSame(20_000, $layaway->remaining_cents->cents);
        $this->assertSame(20_000, (int) DB::table('installments')->where('layaway_id', $layaway->getKey())->sum('amount_cents'));
        $this->assertSame(4, DB::table('installments')->where('layaway_id', $layaway->getKey())->count());
    }

    public function test_with_down_payment_it_stays_pending_without_installments(): void
    {
        $layaway = $this->layawayIn($this->organization);

        $this->withinOrganization($this->organization, fn () => app(OpenLayawayAction::class)->handle($layaway));

        $this->assertSame(LayawayStatus::PendingDownPayment->value, DB::table('layaways')->where('id', $layaway->getKey())->value('status'));
        $this->assertSame(0, DB::table('installments')->where('layaway_id', $layaway->getKey())->count());
    }
}
