<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Actions\Account\UnsubscribeFromCatalogAction;
use App\Models\Account;
use App\Models\CatalogSubscription;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class UnsubscribeFromCatalogActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_leaving_deletes_only_this_accounts_row(): void
    {
        $mine = CatalogSubscription::factory()->create();
        $account = Account::query()->findOrFail($mine->account_id);
        $theirs = CatalogSubscription::factory()->create(['catalog_id' => $mine->catalog_id]);
        $this->withoutOrganization();

        $this->assertTrue(app(UnsubscribeFromCatalogAction::class)->handle($account, $mine->catalog_id));

        $this->assertNull(CatalogSubscription::query()->find($mine->id));
        $this->assertNotNull(CatalogSubscription::query()->find($theirs->id));
    }

    public function test_leaving_what_was_not_followed_is_not_an_error(): void
    {
        $account = Account::factory()->create();

        $this->assertFalse(app(UnsubscribeFromCatalogAction::class)->handle($account, 999_999));
    }
}
