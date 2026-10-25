<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Domain\Money\Money;
use App\Domain\Payment\FifoAllocator;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class FifoAllocatorIsolationTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    public function test_the_installments_of_another_organization_receive_nothing(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();
        $layaway = $this->layawayIn($theirs);

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->withinOrganization($theirs, fn () => app(ActivateLayawayAction::class)->handle($layaway));
        CarbonImmutable::setTestNow();

        $plan = $this->withinOrganization($ours, fn () => app(FifoAllocator::class)->plan($layaway, new Money(3_000)));

        $this->assertSame([], $plan->allocations);
        $this->assertSame(3_000, $plan->unallocatedCents);

        $own = $this->withinOrganization($theirs, fn () => app(FifoAllocator::class)->plan($layaway, new Money(3_000)));
        $this->assertCount(1, $own->allocations);
    }
}
