<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Domain\Money\Money;
use App\Domain\Payment\FifoAllocator;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class FifoAllocatorPlanTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    private Organization $organization;

    private Layaway $layaway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->layaway = $this->layawayIn($this->organization);

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->withinOrganization($this->organization, fn () => app(ActivateLayawayAction::class)->handle($this->layaway));
        CarbonImmutable::setTestNow();
    }

    public function test_it_allocates_to_the_rows_with_what_each_one_lacks(): void
    {
        $ids = $this->installmentIds();
        DB::table('installments')->where('id', $ids[1])->update(['paid_cents' => 3_000, 'status' => 'paid']);
        DB::table('installments')->where('id', $ids[2])->update(['paid_cents' => 1_000, 'status' => 'partially_paid']);

        $plan = $this->withinOrganization($this->organization, fn () => app(FifoAllocator::class)->plan($this->layaway, new Money(5_000)));

        $this->assertSame([['installmentId' => $ids[2], 'cents' => 2_000], ['installmentId' => $ids[3], 'cents' => 3_000]], $plan->allocations);
        $this->assertSame(0, $plan->unallocatedCents);
    }

    public function test_a_payoff_closes_every_installment_and_keeps_the_excess(): void
    {
        $plan = $this->withinOrganization($this->organization, fn () => app(FifoAllocator::class)->plan($this->layaway, new Money(16_000)));

        $this->assertCount(5, $plan->allocations);
        $this->assertSame(15_000, $plan->allocatedCents());
        $this->assertSame(1_000, $plan->unallocatedCents);
    }

    public function test_it_reads_the_installments_for_update(): void
    {
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->withinOrganization($this->organization, fn () => DB::transaction(fn () => app(FifoAllocator::class)->plan($this->layaway, new Money(3_000))));

        $reads = array_values(array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from `installments`')));

        $this->assertCount(1, $reads);
        $this->assertStringContainsString('for update', $reads[0]);
    }

    /**
     * @return array<int, int>
     */
    private function installmentIds(): array
    {
        return $this->withinOrganization($this->organization, fn (): array => Installment::query()
            ->where('layaway_id', $this->layaway->getKey())
            ->pluck('id', 'number')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }
}
