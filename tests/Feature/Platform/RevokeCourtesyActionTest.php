<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Actions\Platform\GrantCourtesyPeriodAction;
use App\Models\Organization;
use App\Models\PlatformActivity;
use App\Models\Subscription;
use App\Support\Auditing\PlatformAudit;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class RevokeCourtesyActionTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-22 10:00:00');
        [$this->organization] = $this->businessWithOwner();
        $this->organization->forceFill(['timezone' => 'America/Caracas'])->save();
        $this->withinOrganization($this->organization, fn () => Subscription::query()->update(['current_period_end' => '2026-11-01 04:00:00']));
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_revoking_restores_the_period_end_from_before_every_stacked_courtesy(): void
    {
        $courtesy = app(GrantCourtesyPeriodAction::class);
        $courtesy->handle($this->organization, $this->operator(), CarbonImmutable::parse('2026-12-01 03:59:59'), 'Retención: migra de otra herramienta');
        $courtesy->handle($this->organization, $this->operator(), CarbonImmutable::parse('2027-01-01 03:59:59'), 'Se amplía la cortesía de retención');
        $operator = $this->asOperator();

        $this->get($this->url())->assertInertia(fn (AssertableInertia $page) => $page
            ->where('courtesy.until', '2027-01-01')
            ->where('courtesy.restoresTo', '2026-11-01'));

        $this->delete($this->url().'/cortesia', ['reason' => 'corto'])->assertSessionHasErrors('reason');
        $this->delete($this->url().'/cortesia', ['reason' => 'El negocio pidió cancelar la promoción'])->assertSessionHasNoErrors();

        $subscription = $this->subscription();
        $this->assertSame('2026-11-01 04:00:00', $subscription->current_period_end?->format('Y-m-d H:i:s'));
        $this->assertNull($subscription->courtesy_until);
        $this->assertSame(1, PlatformActivity::query()->where('action', PlatformAudit::COURTESY_REVOKED)->where('platform_user_id', $operator->getKey())->count());
        $this->get($this->url())->assertInertia(fn (AssertableInertia $page) => $page->where('courtesy', null));
    }

    public function test_a_courtesy_overtaken_by_a_paid_period_is_no_longer_revocable(): void
    {
        app(GrantCourtesyPeriodAction::class)->handle($this->organization, $this->operator(), CarbonImmutable::parse('2026-12-01 03:59:59'), 'Retención: migra de otra herramienta');
        $this->withinOrganization($this->organization, fn () => Subscription::query()->update(['current_period_end' => '2027-01-01 04:00:00']));
        $this->withoutOrganization();
        $this->asOperator();

        $this->delete($this->url().'/cortesia', ['reason' => 'El negocio pidió cancelar la promoción'])->assertSessionHasErrors('courtesy');
        $this->assertSame('2027-01-01 04:00:00', $this->subscription()->current_period_end?->format('Y-m-d H:i:s'));
    }

    public function test_an_expired_courtesy_cannot_be_revoked(): void
    {
        app(GrantCourtesyPeriodAction::class)->handle($this->organization, $this->operator(), CarbonImmutable::parse('2026-12-01 03:59:59'), 'Retención: migra de otra herramienta');
        CarbonImmutable::setTestNow('2026-12-05 10:00:00');
        $this->asOperator();

        $this->delete($this->url().'/cortesia', ['reason' => 'El negocio pidió cancelar la promoción'])->assertSessionHasErrors('courtesy');
    }

    private function url(): string
    {
        return '/plataforma/organizaciones/'.$this->organization->slug;
    }

    private function subscription(): Subscription
    {
        $subscription = $this->withinOrganization($this->organization, fn (): Subscription => Subscription::query()->sole());
        $this->withoutOrganization();

        return $subscription;
    }
}
