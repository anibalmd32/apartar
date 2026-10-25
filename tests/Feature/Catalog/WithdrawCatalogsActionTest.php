<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\WithdrawCatalogsAction;
use App\Models\Catalog;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class WithdrawCatalogsActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->actingAsOrganization($this->organization);
        $this->subscribeWithoutLimits();
    }

    public function test_it_withdraws_the_published_ones_and_names_them(): void
    {
        $first = Catalog::factory()->published()->create();
        $second = Catalog::factory()->expired()->create();
        Catalog::factory()->create(['published_at' => null]);
        $this->recalculateUsage();

        $withdrawn = DB::transaction(fn (): array => app(WithdrawCatalogsAction::class)->handle());

        $this->assertSame([$first->slug, $second->slug], $withdrawn);
        $this->assertSame(0, Catalog::query()->whereNotNull('published_at')->count());
        $this->assertSame($second->slug, $second->fresh()?->slug);
        $this->assertNotNull($second->fresh()->expires_at);
        $this->assertSame(0, (int) DB::table('usage_counters')->where('organization_id', $this->organization->getKey())->where('metric', 'catalogs')->value('value'));
    }

    public function test_with_nothing_published_it_returns_nothing(): void
    {
        $this->assertSame([], DB::transaction(fn (): array => app(WithdrawCatalogsAction::class)->handle()));
    }

    public function test_the_neighbours_catalogs_are_left_alone(): void
    {
        $neighbour = $this->neighbourOrganization();
        $theirs = $this->withinOrganization($neighbour, fn (): Catalog => Catalog::factory()->published()->create());

        DB::transaction(fn (): array => app(WithdrawCatalogsAction::class)->handle());

        $this->assertNotNull($this->withinOrganization($neighbour, fn (): ?Catalog => Catalog::query()->find($theirs->getKey()))?->published_at);
    }
}
