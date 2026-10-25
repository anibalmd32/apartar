<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Actions\Layaway\ExpireLayawaysAction;
use App\Actions\Layaway\ExtendDownPaymentWindowAction;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ExpireLayawaysIsolationTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    public function test_expiring_one_organization_leaves_the_neighbour_untouched(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();

        $mine = $this->layawayIn($ours);
        $neighbours = $this->layawayIn($theirs);
        DB::table('layaways')->update(['expires_at' => '2026-09-01 10:00:00']);

        $outcome = $this->withinOrganization($ours, fn () => app(ExpireLayawaysAction::class)->handle(CarbonImmutable::parse('2026-09-10 10:00:00')));

        $this->assertSame(1, $outcome->expired);
        $this->assertSame('expired', DB::table('layaways')->where('id', $mine->getKey())->value('status'));
        $this->assertSame('pending_down_payment', DB::table('layaways')->where('id', $neighbours->getKey())->value('status'));
        $this->assertNull(DB::table('layaways')->where('id', $neighbours->getKey())->value('stock_released_at'));
    }

    public function test_the_layaway_of_another_organization_cannot_be_extended(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();
        $neighbours = $this->layawayIn($theirs);
        DB::table('layaways')->where('id', $neighbours->getKey())->update(['expires_at' => '2026-09-01 10:00:00']);

        try {
            $this->withinOrganization($ours, fn () => app(ExtendDownPaymentWindowAction::class)->handle($neighbours, CarbonImmutable::parse('2026-09-02 10:00:00')));
            $this->fail('Se prorrogó el apartado de otra organización.');
        } catch (ModelNotFoundException) {
        }

        $this->assertNull(DB::table('layaways')->where('id', $neighbours->getKey())->value('extended_at'));
    }
}
