<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Actions\Account\MuteCatalogAction;
use App\Models\Account;
use App\Models\CatalogSubscription;
use Carbon\CarbonImmutable;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class MuteCatalogActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_muting_keeps_the_subscription_and_unmuting_clears_it(): void
    {
        CarbonImmutable::setTestNow('2026-10-21 10:00:00');
        $subscription = CatalogSubscription::factory()->create(['muted_at' => null]);
        $account = Account::query()->findOrFail($subscription->account_id);
        $this->withoutOrganization();

        $muted = app(MuteCatalogAction::class)->handle($account, $subscription->catalog_id, true);
        $this->assertSame('2026-10-21 10:00:00', $muted?->muted_at?->format('Y-m-d H:i:s'));

        CarbonImmutable::setTestNow('2026-10-25 10:00:00');
        $again = app(MuteCatalogAction::class)->handle($account, $subscription->catalog_id, true);
        $this->assertSame('2026-10-21 10:00:00', $again?->muted_at?->format('Y-m-d H:i:s'));

        $this->assertNull(app(MuteCatalogAction::class)->handle($account, $subscription->catalog_id, false)?->muted_at);
        $this->assertSame(1, CatalogSubscription::query()->count());

        CarbonImmutable::setTestNow();
    }

    public function test_muting_what_is_not_followed_returns_null(): void
    {
        $other = CatalogSubscription::factory()->create();
        $account = Account::factory()->create();
        $this->withoutOrganization();

        $this->assertNull(app(MuteCatalogAction::class)->handle($account, $other->catalog_id, true));
        $this->assertNull($other->fresh()?->muted_at);
    }
}
