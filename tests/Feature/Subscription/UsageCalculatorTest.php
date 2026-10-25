<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Export\ExportStatus;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Jobs\RecalculateUsage;
use App\Models\Catalog;
use App\Models\ExportJob;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class UsageCalculatorTest extends TestCase
{
    use CreatesLayaways;
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->subscribeWithCounters($this->organization);

        Cache::forget(RecalculateUsage::lockFor(null));
    }

    public function test_users_count_the_active_and_the_pending_invitations(): void
    {
        $this->withinOrganization($this->organization, function (): void {
            User::factory()->owner()->create();
            User::factory()->create();
            User::factory()->invited()->create();
            User::factory()->disabled()->create();
        });
        $this->withinOrganization($this->neighbourOrganization(), fn () => User::factory()->count(4)->create());

        $this->assertSame(3, $this->measured(UsageMetric::Users));
    }

    public function test_catalogs_count_the_published_ones_that_have_not_expired(): void
    {
        $catalogs = $this->withinOrganization($this->organization, fn (): array => [
            Catalog::factory()->published()->create(),
            Catalog::factory()->published()->unlisted()->create(),
            Catalog::factory()->published()->expiresAt(now()->addDay())->create(),
            Catalog::factory()->create(),
            Catalog::factory()->expired()->create(),
        ]);
        $this->withinOrganization($this->neighbourOrganization(), fn () => Catalog::factory()->published()->count(2)->create());

        $this->assertSame(
            [CatalogVisibility::Listed, CatalogVisibility::Unlisted, CatalogVisibility::Listed, CatalogVisibility::Draft, CatalogVisibility::Expired],
            array_map(fn (Catalog $catalog): CatalogVisibility => $catalog->visibility(), $catalogs),
        );

        $expected = count(array_filter($catalogs, fn (Catalog $catalog): bool => $catalog->visibility()->countsTowardPlanLimit()));

        $this->assertSame(3, $expected);
        $this->assertSame($expected, $this->measured(UsageMetric::Catalogs));
    }

    public function test_products_count_the_ones_neither_archived_nor_deleted(): void
    {
        $this->withinOrganization($this->organization, function (): void {
            Product::factory()->create();
            Product::factory()->published()->create();
            Product::factory()->archived()->create();
            Product::factory()->create()->delete();
        });
        $this->withinOrganization($this->neighbourOrganization(), fn () => Product::factory()->count(3)->create());

        $this->assertSame(2, $this->measured(UsageMetric::Products));
    }

    public function test_active_layaways_count_the_three_states_that_still_collect(): void
    {
        $this->withinOrganization($this->organization, function (): void {
            foreach (LayawayStatus::cases() as $status) {
                $this->forceStatus(Layaway::factory()->create(), $status);
            }
        });
        $this->withinOrganization($this->neighbourOrganization(), fn () => $this->forceStatus(Layaway::factory()->create(), LayawayStatus::Active));

        $this->assertSame(3, $this->measured(UsageMetric::ActiveLayaways));
    }

    public function test_storage_adds_receipts_and_product_images_and_rounds_up_to_whole_megabytes(): void
    {
        $mb = 1024 * 1024;

        $this->withinOrganization($this->organization, function () use ($mb): void {
            Receipt::factory()->create(['file_size' => $mb]);
            Receipt::factory()->create(['file_size' => 300_000]);

            $this->mediaOf(Product::factory()->create(), $mb);

            $gone = Product::factory()->create();
            $this->mediaOf($gone, 200_000);
            $gone->delete();
        });
        $this->withinOrganization($this->neighbourOrganization(), function () use ($mb): void {
            Receipt::factory()->create(['file_size' => 5 * $mb]);
            $this->mediaOf(Product::factory()->create(), 5 * $mb);
        });

        $ours = $this->withinOrganization($this->organization, fn (): Product => Product::query()->firstOrFail());
        $this->mediaOf($ours, 9 * $mb, modelType: 'App\\Models\\Catalog');

        $this->assertSame(3, $this->measured(UsageMetric::StorageMb));
    }

    public function test_storage_adds_the_live_exports(): void
    {
        $mb = 1024 * 1024;

        $this->withinOrganization($this->organization, function () use ($mb): void {
            Receipt::factory()->create(['file_size' => $mb]);
            ExportJob::factory()->completed(bytes: 600_000)->create();
            ExportJob::factory()->completed(bytes: 9 * $mb)->create(['status' => ExportStatus::Expired]);
            ExportJob::factory()->create(['status' => ExportStatus::Processing, 'file_size' => 9 * $mb]);
            ExportJob::factory()->create(['status' => ExportStatus::Failed, 'file_size' => 9 * $mb]);
        });
        $this->withinOrganization($this->neighbourOrganization(), fn () => ExportJob::factory()->completed(bytes: 9 * $mb)->create());

        $this->assertSame(2, $this->measured(UsageMetric::StorageMb));
    }

    public function test_storage_is_zero_without_files(): void
    {
        $this->assertSame(0, $this->measured(UsageMetric::StorageMb));
    }

    public function test_payment_accounts_count_the_active_ones(): void
    {
        $this->withinOrganization($this->organization, function (): void {
            PaymentAccount::factory()->create();
            PaymentAccount::factory()->zelle()->create();
            PaymentAccount::factory()->inactive()->create();
        });
        $this->withinOrganization($this->neighbourOrganization(), fn () => PaymentAccount::factory()->count(2)->create());

        $this->assertSame(2, $this->measured(UsageMetric::PaymentAccounts));
    }

    public function test_the_job_corrects_an_injected_drift_and_logs_it(): void
    {
        $this->withinOrganization($this->organization, function (): void {
            Product::factory()->count(2)->create();
        });
        $this->setCounter(UsageMetric::Products, 9);
        $logger = Log::spy();

        $result = app()->call([new RecalculateUsage, 'handle']);

        $this->assertSame(2, $this->counter(UsageMetric::Products));
        $this->assertSame(2, $this->withinOrganization($this->organization, fn (): int => Product::query()->count()));
        $this->assertIsArray($result);
        $this->assertSame(
            [['organization_id' => $this->organization->getKey(), 'metric' => 'products', 'stored' => 9, 'measured' => 2]],
            array_map(fn ($drift): array => $drift->toArray(), $result['drifts']),
        );
        $logger->shouldHaveReceived('warning')->with('usage_counter.drift', [
            'organization_id' => $this->organization->getKey(),
            'metric' => 'products',
            'stored' => 9,
            'measured' => 2,
        ])->once();
    }

    public function test_the_job_corrects_a_drift_in_each_of_the_six_metrics(): void
    {
        foreach (UsageMetric::cases() as $metric) {
            $this->setCounter($metric, 50);
        }
        $logger = Log::spy();

        $result = app()->call([new RecalculateUsage, 'handle']);

        foreach (UsageMetric::cases() as $metric) {
            $this->assertSame(0, $this->counter($metric), $metric->value);
        }
        $this->assertIsArray($result);
        $this->assertEqualsCanonicalizing(
            array_values(array_map(fn (UsageMetric $metric): string => $metric->value, array_filter(
                UsageMetric::cases(),
                fn (UsageMetric $metric): bool => ! $metric->shrinksWithTime(),
            ))),
            array_map(fn ($drift): string => $drift->metric->value, $result['drifts']),
        );
        $logger->shouldHaveReceived('warning')->with('usage_counter.drift', \Mockery::type('array'))->times(5);
    }

    public function test_a_refresh_is_not_logged_as_drift(): void
    {
        $this->withinOrganization($this->organization, fn () => Receipt::factory()->create(['file_size' => 3 * 1024 * 1024]));
        $logger = Log::spy();

        $this->calculator()->refresh($this->organization, UsageMetric::StorageMb);

        $this->assertSame(3, $this->counter(UsageMetric::StorageMb));
        $logger->shouldNotHaveReceived('warning');
    }

    public function test_the_hourly_pass_takes_out_a_catalog_that_expired(): void
    {
        $this->withinOrganization($this->organization, fn () => Catalog::factory()->published()->expiresAt(now()->addMinutes(30))->create());
        $this->calculator()->recalculate($this->organization);
        $this->assertSame(1, $this->counter(UsageMetric::Catalogs));

        $this->travel(1)->hours();
        $logger = Log::spy();

        app()->call([new RecalculateUsage(metric: UsageMetric::Catalogs), 'handle']);

        $this->assertSame(0, $this->counter(UsageMetric::Catalogs));
        $logger->shouldNotHaveReceived('warning');
    }

    public function test_an_expired_catalog_is_corrected_without_being_reported_as_drift(): void
    {
        $this->withinOrganization($this->organization, fn () => Catalog::factory()->published()->create());
        $this->setCounter(UsageMetric::Catalogs, 3);

        $drifts = $this->calculator()->recalculateMany([(int) $this->organization->getKey()], UsageMetric::Catalogs);

        $this->assertSame([], $drifts);
        $this->assertSame(1, $this->counter(UsageMetric::Catalogs));

        $this->setCounter(UsageMetric::Catalogs, 0);

        $drifts = $this->calculator()->recalculateMany([(int) $this->organization->getKey()], UsageMetric::Catalogs);

        $this->assertCount(1, $drifts);
        $this->assertSame(1, $this->counter(UsageMetric::Catalogs));
    }

    public function test_a_counter_that_was_right_is_only_stamped(): void
    {
        $this->travelTo(now()->subDay());
        $this->setCounter(UsageMetric::Users, 0);
        $this->travelBack();

        $this->calculator()->recalculate($this->organization, UsageMetric::Users);

        $counter = $this->withinOrganization($this->organization, fn () => UsageCounter::query()->where('metric', UsageMetric::Users)->firstOrFail());
        $this->assertSame(0, $counter->value);
        $this->assertTrue($counter->computed_at->isToday());
    }

    public function test_a_missing_counter_is_created_with_its_measure(): void
    {
        $this->withinOrganization($this->organization, function (): void {
            UsageCounter::query()->where('metric', UsageMetric::PaymentAccounts)->delete();
            PaymentAccount::factory()->create();
        });

        $this->calculator()->recalculate($this->organization);

        $this->assertSame(1, $this->counter(UsageMetric::PaymentAccounts));
    }

    public function test_a_counter_never_goes_below_zero(): void
    {
        $logger = Log::spy();

        $value = $this->withinOrganization($this->organization, fn (): int => DB::transaction(
            fn (): int => app(LimitEnforcer::class)->adjust(UsageMetric::Products, -3),
        ));

        $this->assertSame(0, $value);
        $this->assertSame(0, $this->counter(UsageMetric::Products));
        $logger->shouldHaveReceived('warning')->with('usage_counter.below_zero', \Mockery::type('array'))->once();

        $this->withinOrganization($this->organization, fn () => Product::factory()->create());
        $this->calculator()->recalculate($this->organization);

        $this->assertSame(1, $this->counter(UsageMetric::Products));
    }

    public function test_the_counter_moves_inside_the_mutations_transaction(): void
    {
        $this->withinOrganization($this->organization, function (): void {
            try {
                DB::transaction(function (): void {
                    Product::factory()->create();
                    app(LimitEnforcer::class)->adjust(UsageMetric::Products, 1);

                    $this->assertSame(1, (int) UsageCounter::query()->where('metric', UsageMetric::Products)->value('value'));

                    throw new \RuntimeException('se deshace');
                });
            } catch (\RuntimeException) {
            }
        });

        $this->assertSame(0, $this->counter(UsageMetric::Products));
    }

    public function test_completing_a_layaway_frees_its_slot(): void
    {
        $layaway = $this->withinOrganization($this->organization, function (): Layaway {
            $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
            $this->assertSame(1, (int) UsageCounter::query()->where('metric', UsageMetric::ActiveLayaways)->value('value'));

            return $layaway;
        });

        $this->withinOrganization($this->organization, fn () => app(LayawayStateMachine::class)->transition($layaway, LayawayStatus::Completed));

        $this->assertSame(0, $this->counter(UsageMetric::ActiveLayaways));
        $this->assertSame(0, $this->measured(UsageMetric::ActiveLayaways));
    }

    public function test_the_mass_recalculation_makes_the_same_queries_for_three_organizations_or_nine(): void
    {
        $three = $this->organizationsWithUsage(3);
        $nine = $this->organizationsWithUsage(9);

        $this->assertSame($this->queriesToRecalculate($three), $this->queriesToRecalculate($nine));
    }

    public function test_the_job_makes_the_same_queries_for_three_organizations_or_nine(): void
    {
        $this->organizationsWithUsage(3);
        $afterThree = $this->queriesOfTheJob();

        $this->organizationsWithUsage(6);
        $afterNine = $this->queriesOfTheJob();

        $this->assertSame($afterThree, $afterNine);
    }

    public function test_registering_an_organization_counts_its_owner(): void
    {
        $this->seed(PlanSeeder::class);

        $this->post('/registro', [
            'organization_name' => 'Ferretería Contada',
            'organization_slug' => 'ferreteria-contada',
            'owner_name' => 'Ana Dueña',
            'owner_email' => 'ana@contada.test',
            'owner_password' => 'ContraseñaLarga123',
            'owner_password_confirmation' => 'ContraseñaLarga123',
        ])->assertRedirect();

        $organization = Organization::query()->where('slug', 'ferreteria-contada')->firstOrFail();

        $this->assertSame(
            ['active_layaways' => 0, 'catalogs' => 0, 'payment_accounts' => 0, 'products' => 0, 'storage_mb' => 0, 'users' => 1],
            $this->withinOrganization($organization, fn (): array => UsageCounter::query()->orderBy('metric')->pluck('value', 'metric')->map(fn ($value): int => (int) $value)->all()),
        );
    }

    public function test_the_command_corrects_and_lists_what_it_found(): void
    {
        $this->withinOrganization($this->organization, fn () => User::factory()->count(2)->create());
        $this->setCounter(UsageMetric::Users, 7);

        $this->artisan('subscriptions:recalculate-usage', ['--organization' => (string) $this->organization->getKey()])
            ->expectsOutputToContain('1 organizaciones recalculadas; 1 contadores descuadrados, ya corregidos.')
            ->expectsTable(['Organización', 'Métrica', 'Decía', 'Mide'], [[$this->organization->getKey(), 'users', 7, 2]])
            ->assertExitCode(0);

        $this->assertSame(2, $this->counter(UsageMetric::Users));
    }

    public function test_the_command_narrows_by_metric_and_rejects_what_does_not_exist(): void
    {
        $this->withinOrganization($this->organization, fn () => User::factory()->count(2)->create());
        $this->setCounter(UsageMetric::Users, 7);
        $this->setCounter(UsageMetric::Products, 7);

        $this->artisan('subscriptions:recalculate-usage', ['--metric' => 'users'])->assertExitCode(0);

        $this->assertSame(2, $this->counter(UsageMetric::Users));
        $this->assertSame(7, $this->counter(UsageMetric::Products));

        $this->artisan('subscriptions:recalculate-usage', ['--metric' => 'usuarios'])
            ->expectsOutputToContain('No existe la métrica «usuarios».')
            ->assertExitCode(1);
        $this->artisan('subscriptions:recalculate-usage', ['--organization' => '1.5'])->assertExitCode(1);
        $this->artisan('subscriptions:recalculate-usage', ['--organization' => '999999'])->assertExitCode(1);
    }

    public function test_a_second_pass_of_the_same_scope_does_not_run_while_one_is_in_progress(): void
    {
        $lock = Cache::lock(RecalculateUsage::lockFor(null), 60);
        $this->assertTrue($lock->get());

        try {
            $this->assertNull(app()->call([new RecalculateUsage, 'handle']));
            $this->artisan('subscriptions:recalculate-usage')
                ->expectsOutputToContain('Hay otra pasada del recálculo en curso')
                ->assertExitCode(1);

            $this->assertIsArray(app()->call([new RecalculateUsage(metric: UsageMetric::Catalogs), 'handle']));
        } finally {
            $lock->release();
        }
    }

    public function test_it_is_scheduled_nightly_and_hourly_for_catalogs(): void
    {
        $events = collect(app(Schedule::class)->events())->keyBy(fn ($event): ?string => $event->description);

        $nightly = $events->get('subscriptions:recalculate-usage');
        $hourly = $events->get('subscriptions:recalculate-usage:catalogs');

        $this->assertInstanceOf(CallbackEvent::class, $nightly);
        $this->assertSame('0 2 * * *', $nightly->expression);
        $this->assertTrue($nightly->withoutOverlapping);
        $this->assertInstanceOf(CallbackEvent::class, $hourly);
        $this->assertSame('15 * * * *', $hourly->expression);
        $this->assertTrue($hourly->withoutOverlapping);

        Queue::fake();

        $nightly->run(app());
        $hourly->run(app());

        Queue::assertPushedOn('telemetry', RecalculateUsage::class, fn (RecalculateUsage $job): bool => $job->metric === null && $job->organizationId === null);
        Queue::assertPushedOn('telemetry', RecalculateUsage::class, fn (RecalculateUsage $job): bool => $job->metric === UsageMetric::Catalogs && $job->organizationId === null);
        Queue::assertPushed(RecalculateUsage::class, 2);
    }

    private function calculator(): UsageCalculator
    {
        return app(UsageCalculator::class);
    }

    private function measured(UsageMetric $metric): int
    {
        return $this->withinOrganization($this->organization, fn (): int => $this->calculator()->measure($metric));
    }

    private function counter(UsageMetric $metric): int
    {
        return $this->withinOrganization(
            $this->organization,
            fn (): int => (int) UsageCounter::query()->where('metric', $metric)->value('value'),
        );
    }

    private function setCounter(UsageMetric $metric, int $value): void
    {
        $this->withinOrganization($this->organization, fn () => UsageCounter::query()->updateOrCreate(
            ['metric' => $metric],
            ['value' => $value, 'computed_at' => now()],
        ));
    }

    private function subscribeWithCounters(Organization $organization): void
    {
        $this->withinOrganization($organization, function (): void {
            Subscription::factory()->forPlan(Plan::factory()->hidden()->create())->create();

            foreach (UsageMetric::cases() as $metric) {
                UsageCounter::factory()->forMetric($metric)->create();
            }
        });
    }

    private function forceStatus(Layaway $layaway, LayawayStatus $status): void
    {
        DB::table('layaways')->where('id', $layaway->getKey())->update(['status' => $status->value]);
    }

    private function mediaOf(Product $product, int $bytes, ?string $modelType = null): void
    {
        DB::table('media')->insert([
            'model_type' => $modelType ?? $product->getMorphClass(),
            'model_id' => $product->getKey(),
            'uuid' => (string) Str::uuid(),
            'collection_name' => Product::IMAGES,
            'name' => 'foto',
            'file_name' => 'foto.png',
            'mime_type' => 'image/png',
            'disk' => 'public',
            'conversions_disk' => 'public',
            'size' => $bytes,
            'manipulations' => '[]',
            'custom_properties' => '[]',
            'generated_conversions' => '[]',
            'responsive_images' => '[]',
            'order_column' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<int>
     */
    private function organizationsWithUsage(int $count): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $organization = Organization::factory()->create();
            $this->subscribeWithCounters($organization);

            $this->withinOrganization($organization, function (): void {
                User::factory()->create();
                Product::factory()->create();
                Catalog::factory()->published()->create();
                PaymentAccount::factory()->create();
                Receipt::factory()->create();
            });

            $ids[] = (int) $organization->getKey();
        }

        $this->calculator()->recalculateMany($ids);

        return $ids;
    }

    private function queriesOfTheJob(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        app()->call([new RecalculateUsage, 'handle']);

        $count = count(array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => ! str_contains(strtolower($query['query']), 'cache'),
        ));
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @param  list<int>  $ids
     */
    private function queriesToRecalculate(array $ids): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->calculator()->recalculateMany($ids);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
