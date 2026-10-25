<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Domain\Layaway\LayawayBalanceRecalculator;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class LayawayBalanceIsolationTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_balance_of_another_organization_is_neither_written_nor_compared(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();
        $neighbours = $this->activated($theirs);
        DB::table('layaways')->where('id', $neighbours->getKey())->update(['paid_cents' => 1]);

        foreach (['recalculate', 'diff'] as $method) {
            try {
                $this->withinOrganization($ours, fn () => app(LayawayBalanceRecalculator::class)->{$method}($neighbours));
                $this->fail("{$method}() alcanzó el apartado de otra organización.");
            } catch (ModelNotFoundException) {
            }
        }

        $this->assertSame(1, (int) DB::table('layaways')->where('id', $neighbours->getKey())->value('paid_cents'));
    }

    public function test_payments_of_another_organization_do_not_count(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();
        $mine = $this->activated($ours);
        $this->withinOrganization($ours, fn () => Payment::factory()->createOne(['layaway_id' => $mine->getKey(), 'amount_cents_usd' => 5_000, 'unallocated_cents' => 0]));

        $foreign = $this->withinOrganization($theirs, fn (): Payment => Payment::factory()->createOne(['layaway_id' => $this->activated($theirs)->getKey()]));
        DB::table('payments')->where('id', $foreign->getKey())->update(['layaway_id' => $mine->getKey()]);

        $this->assertNull($this->withinOrganization($ours, fn () => app(LayawayBalanceRecalculator::class)->diff($mine)));
    }

    public function test_installments_of_another_organization_do_not_count(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();
        $mine = $this->activated($ours);
        $neighbours = $this->activated($theirs);

        DB::table('installments')->where('layaway_id', $neighbours->getKey())->where('number', 1)
            ->update(['layaway_id' => $mine->getKey(), 'number' => 99, 'paid_cents' => 2_000, 'due_date' => '2026-09-01', 'status' => 'overdue']);

        $this->withinOrganization($ours, fn () => app(LayawayBalanceRecalculator::class)->recalculate($mine));

        $row = DB::table('layaways')->where('id', $mine->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame(5_000, (int) $row->paid_cents);
        $this->assertSame(15_000, (int) $row->remaining_cents);
        $this->assertSame('2026-09-25', $row->next_due_date);
        $this->assertSame('active', $row->status);
    }

    private function activated(Organization $organization): Layaway
    {
        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $layaway = $this->layawayIn($organization);
        $this->withinOrganization($organization, fn () => app(ActivateLayawayAction::class)->handle($layaway));

        return $layaway;
    }
}
