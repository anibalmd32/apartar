<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Auditing\AuditEvent;
use App\Models\AuditEntry;
use App\Models\Plan;
use App\Models\PlatformActivity;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\TestCase;

final class OverridePlanActionTest extends TestCase
{
    use ActsAsPlatformOperator;
    use RefreshDatabase;

    public function test_an_upgrade_needs_a_reason_and_is_audited_twice(): void
    {
        $free = Plan::factory()->create(['code' => 'free', 'position' => 1]);
        $pro = Plan::factory()->create(['code' => 'pro', 'position' => 2]);
        [$organization] = $this->businessWithOwner($free);
        $this->asOperator();

        $this->post('/plataforma/organizaciones/'.$organization->slug.'/plan', ['plan' => 'pro', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post('/plataforma/organizaciones/'.$organization->slug.'/plan', ['plan' => 'pro', 'reason' => 'Plan negociado con el dueño'])->assertSessionHasNoErrors();

        $this->assertSame($pro->getKey(), $this->withinOrganization($organization, fn (): int => Subscription::query()->sole()->plan_id));
        $this->assertSame(1, $this->withinOrganization($organization, fn (): int => AuditEntry::query()->ofEvent(AuditEvent::PlatformSubscriptionOverridden)->count()));
        $this->assertSame(1, PlatformActivity::query()->where('action', 'subscription.plan_overridden')->count());
    }

    public function test_a_downgrade_follows_the_downgrade_rules(): void
    {
        $free = Plan::factory()->create(['code' => 'free', 'position' => 1]);
        $pro = Plan::factory()->create(['code' => 'pro', 'position' => 2]);
        [$organization] = $this->businessWithOwner($pro);
        $this->asOperator();

        $this->post('/plataforma/organizaciones/'.$organization->slug.'/plan', ['plan' => 'free', 'reason' => 'Corrección de un error de alta'])->assertSessionHasNoErrors();

        $subscription = $this->withinOrganization($organization, fn (): Subscription => Subscription::query()->sole());
        $this->assertSame($free->getKey(), $subscription->plan_id);
        $this->assertNull($subscription->scheduled_plan_id);
        $this->assertNotNull($subscription->downgrade_applied_at);
    }
}
