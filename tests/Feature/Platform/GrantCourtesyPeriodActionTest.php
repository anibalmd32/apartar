<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Actions\Platform\GrantCourtesyPeriodAction;
use App\Domain\Auditing\AuditEvent;
use App\Models\AuditEntry;
use App\Models\PlatformActivity;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\TestCase;

final class GrantCourtesyPeriodActionTest extends TestCase
{
    use ActsAsPlatformOperator;
    use RefreshDatabase;

    public function test_it_extends_the_period_with_a_reason_and_audits_both_sides(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 10:00:00');
        [$organization] = $this->businessWithOwner();
        $this->withinOrganization($organization, fn () => Subscription::query()->update(['current_period_end' => '2026-11-01 00:00:00']));
        $this->withoutOrganization();
        $operator = $this->asOperator();

        $this->post('/plataforma/organizaciones/'.$organization->slug.'/cortesia', ['until' => '2026-12-01', 'reason' => 'corto'])->assertSessionHasErrors('reason');
        $this->post('/plataforma/organizaciones/'.$organization->slug.'/cortesia', ['until' => '2026-12-01', 'reason' => 'Retención: el negocio migra de otra herramienta'])->assertSessionHasNoErrors();

        $end = $this->withinOrganization($organization, fn () => Subscription::query()->sole()->current_period_end);
        $this->assertSame('2026-12-01', $end?->setTimezone($organization->timezone)->toDateString());
        $this->assertSame(1, $this->withinOrganization($organization, fn (): int => AuditEntry::query()->ofEvent(AuditEvent::PlatformSubscriptionOverridden)->count()));
        $this->assertSame(1, PlatformActivity::query()->where('action', 'subscription.courtesy_granted')->where('platform_user_id', $operator->getKey())->count());

        CarbonImmutable::setTestNow();
    }

    public function test_it_must_extend_and_has_a_ceiling(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 10:00:00');
        [$organization] = $this->businessWithOwner();
        $this->withinOrganization($organization, fn () => Subscription::query()->update(['current_period_end' => '2026-11-15 00:00:00']));
        $this->withoutOrganization();
        $operator = $this->operator();
        $action = app(GrantCourtesyPeriodAction::class);

        foreach (['2026-11-10 23:59:59', '2027-12-01 00:00:00'] as $until) {
            try {
                $action->handle($organization, $operator, CarbonImmutable::parse($until), 'Motivo suficientemente largo');
                $this->fail('Tenía que rechazarse: '.$until);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        CarbonImmutable::setTestNow();
    }
}
