<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Actions\Catalog\PublishCatalogAction;
use App\Actions\Catalog\PublishCatalogData;
use App\Actions\Catalog\UnpublishCatalogAction;
use App\Actions\Organization\DisableTeamMemberAction;
use App\Actions\Organization\EnableTeamMemberAction;
use App\Actions\Organization\InviteTeamMemberAction;
use App\Actions\Organization\UpsertPaymentAccountAction;
use App\Actions\Organization\UpsertPaymentAccountData;
use App\Actions\Product\ChangeProductStatusAction;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class UsageCounterMovesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        $this->actingAsOrganization($this->organization);

        Subscription::factory()->forPlan(Plan::factory()->unlimited()->create())->create();
        $this->owner = User::factory()->owner()->create();
    }

    public function test_inviting_revoking_and_a_stale_second_revoke(): void
    {
        $this->recalculateUsage();

        $invited = $this->insideItsTransaction(fn (): User => app(InviteTeamMemberAction::class)
            ->handle('Luis', 'luis@ejemplo.test', OrganizationRole::Member, $this->owner));
        $this->assertSame(2, $this->counter(UsageMetric::Users));

        $stale = User::query()->findOrFail($invited->getKey());

        $this->insideItsTransaction(fn () => app(InviteTeamMemberAction::class)->revoke($invited, $this->owner));
        $this->assertSame(1, $this->counter(UsageMetric::Users));

        app(InviteTeamMemberAction::class)->revoke($stale, $this->owner);
        $this->assertSame(1, $this->counter(UsageMetric::Users));
    }

    public function test_disabling_and_enabling_move_users_once_each(): void
    {
        $member = User::factory()->create();
        $this->recalculateUsage();
        $stale = User::query()->findOrFail($member->getKey());

        $this->insideItsTransaction(fn () => app(DisableTeamMemberAction::class)->handle($member, $this->owner));
        $this->assertSame(1, $this->counter(UsageMetric::Users));

        app(DisableTeamMemberAction::class)->handle($stale, $this->owner);
        $this->assertSame(1, $this->counter(UsageMetric::Users));

        $staleDisabled = User::query()->findOrFail($member->getKey());

        $this->insideItsTransaction(fn () => app(EnableTeamMemberAction::class)->handle($member->refresh(), $this->owner));
        $this->assertSame(2, $this->counter(UsageMetric::Users));

        app(EnableTeamMemberAction::class)->handle($staleDisabled, $this->owner);
        $this->assertSame(2, $this->counter(UsageMetric::Users));
    }

    public function test_payment_accounts_move_with_every_switch(): void
    {
        $this->recalculateUsage();
        $upsert = app(UpsertPaymentAccountAction::class);

        $account = $this->insideItsTransaction(fn (): PaymentAccount => $upsert->handle($this->accountData(), $this->owner));
        $this->assertSame(1, $this->counter(UsageMetric::PaymentAccounts));

        $upsert->handle($this->accountData(holderName: 'Otro titular'), $this->owner, $account);
        $this->assertSame(1, $this->counter(UsageMetric::PaymentAccounts));

        $upsert->handle($this->accountData(isActive: false), $this->owner, $account);
        $this->assertSame(0, $this->counter(UsageMetric::PaymentAccounts));

        $upsert->handle($this->accountData(), $this->owner, $account);
        $this->assertSame(1, $this->counter(UsageMetric::PaymentAccounts));

        $dormant = $upsert->handle($this->accountData(identifier: '0412-7654321', isActive: false), $this->owner);
        $upsert->delete($dormant, $this->owner);
        $this->assertSame(1, $this->counter(UsageMetric::PaymentAccounts));

        $stale = PaymentAccount::query()->findOrFail($account->getKey());

        $this->insideItsTransaction(fn () => $upsert->delete($account, $this->owner));
        $this->assertSame(0, $this->counter(UsageMetric::PaymentAccounts));

        $upsert->delete($stale, $this->owner);
        $this->assertSame(0, $this->counter(UsageMetric::PaymentAccounts));
    }

    public function test_archiving_twice_with_a_stale_product_frees_one_slot(): void
    {
        $product = Product::factory()->create();
        $this->recalculateUsage();
        $stale = Product::query()->findOrFail($product->getKey());

        $this->insideItsTransaction(fn () => app(ChangeProductStatusAction::class)->archive($product, $this->owner));
        $this->assertSame(0, $this->counter(UsageMetric::Products));

        app(ChangeProductStatusAction::class)->archive($stale, $this->owner);
        $this->assertSame(0, $this->counter(UsageMetric::Products));
    }

    public function test_publishing_and_unpublishing_with_stale_catalogs_move_once(): void
    {
        $catalog = $this->publishableCatalog();
        $this->recalculateUsage();
        $stale = Catalog::query()->findOrFail($catalog->getKey());

        $this->insideItsTransaction(fn () => $this->publish($catalog));
        $this->assertSame(1, $this->counter(UsageMetric::Catalogs));

        $this->publish($stale);
        $this->assertSame(1, $this->counter(UsageMetric::Catalogs));

        $staleBeforeUnpublish = Catalog::query()->findOrFail($catalog->getKey());

        $this->insideItsTransaction(fn () => app(UnpublishCatalogAction::class)->handle($catalog->refresh(), $this->owner));
        $this->assertSame(0, $this->counter(UsageMetric::Catalogs));

        app(UnpublishCatalogAction::class)->handle($staleBeforeUnpublish, $this->owner);
        $this->assertSame(0, $this->counter(UsageMetric::Catalogs));
    }

    public function test_republishing_an_expired_catalog_before_the_sweep_counts_it_once(): void
    {
        $catalog = $this->publishableCatalog();
        $this->recalculateUsage();
        $this->publish($catalog, CarbonImmutable::now()->addHour());
        $this->assertSame(1, $this->counter(UsageMetric::Catalogs));

        $this->travel(2)->hours();

        $this->publish($catalog->refresh(), CarbonImmutable::now()->addWeek());

        $this->assertSame(1, $this->counter(UsageMetric::Catalogs));
    }

    public function test_unpublishing_an_expired_catalog_before_the_sweep_leaves_no_unit_behind(): void
    {
        $catalog = $this->publishableCatalog();
        $this->recalculateUsage();
        $this->publish($catalog, CarbonImmutable::now()->addHour());

        $this->travel(2)->hours();

        app(UnpublishCatalogAction::class)->handle($catalog->refresh(), $this->owner);

        $this->assertSame(0, $this->counter(UsageMetric::Catalogs));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T
     */
    private function insideItsTransaction(Closure $action): mixed
    {
        $baseline = DB::transactionLevel();
        $levels = [];

        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$levels): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'usage_counters') && (str_starts_with($sql, 'update') || str_starts_with($sql, 'insert'))) {
                $levels[] = DB::transactionLevel();
            }
        });

        $result = $action();

        $this->assertNotSame([], $levels, 'La Action no escribió el contador.');

        foreach ($levels as $level) {
            $this->assertGreaterThan($baseline, $level, 'El contador se escribió fuera de la transacción de la Action.');
        }

        Event::forget(QueryExecuted::class);

        return $result;
    }

    private function counter(UsageMetric $metric): int
    {
        return (int) UsageCounter::query()->where('metric', $metric)->value('value');
    }

    private function accountData(
        string $identifier = '0414-1234567',
        string $holderName = 'Carlos Rojas',
        bool $isActive = true,
    ): UpsertPaymentAccountData {
        return new UpsertPaymentAccountData(
            method: PaymentMethod::PagoMovil,
            bank: 'Banesco',
            holderName: $holderName,
            holderDocument: 'V-12345678',
            identifier: $identifier,
            instructions: null,
            isActive: $isActive,
        );
    }

    private function publishableCatalog(): Catalog
    {
        PaymentAccount::factory()->create();

        $catalog = Catalog::factory()->create();
        $catalog->products()->attach(Product::factory()->published()->create(), ['position' => 1]);

        return $catalog;
    }

    private function publish(Catalog $catalog, ?CarbonImmutable $expiresAt = null): Catalog
    {
        return app(PublishCatalogAction::class)->handle(
            $catalog,
            new PublishCatalogData(isListed: true, expiresAt: $expiresAt),
            $this->owner,
        );
    }
}
