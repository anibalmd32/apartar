<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use App\Domain\Collections\CollectionPreset;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Settings\SettingKey;
use App\Models\Catalog;
use App\Models\Category;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Product;
use App\Queries\CollectionFilters;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CollectionFiltersTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 12:00:00', 'America/Caracas'));
        $this->organization = $this->subscribe(Organization::factory()->create(['timezone' => 'Asia/Tokyo']));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  list<string>  $dates
     * @param  list<string>  $expected
     */
    #[DataProvider('presetBoundaries')]
    public function test_each_preset_at_its_exact_boundaries(CollectionPreset $preset, array $dates, array $expected): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);

        foreach ($dates as $number => $date) {
            $this->installment($layaway, $number + 1, $date);
        }

        $this->assertSame($expected, $this->dueDates(new CollectionFilters($preset)));
    }

    /**
     * @return array<string, array{CollectionPreset, list<string>, list<string>}>
     */
    public static function presetBoundaries(): array
    {
        return [
            'vencen hoy, el día del negocio' => [CollectionPreset::DueToday, ['2026-09-10', '2026-09-11', '2026-09-12'], ['2026-09-11']],
            'vencen en 7 días, de hoy a hoy + 7' => [CollectionPreset::DueWithin7Days, ['2026-09-10', '2026-09-11', '2026-09-18', '2026-09-19'], ['2026-09-11', '2026-09-18']],
            'mora de más de 15 días pasada la gracia' => [CollectionPreset::OverdueOver15Days, ['2026-08-23', '2026-08-24', '2026-09-01'], ['2026-08-23']],
            'corte del 1 al 15' => [CollectionPreset::FortnightFirstHalf, ['2026-08-31', '2026-09-01', '2026-09-15', '2026-09-16'], ['2026-09-01', '2026-09-15']],
            'corte del 16 a fin de mes' => [CollectionPreset::FortnightSecondHalf, ['2026-09-15', '2026-09-16', '2026-09-30', '2026-10-01'], ['2026-09-16', '2026-09-30']],
        ];
    }

    public function test_completed_not_delivered_lists_layaways_even_without_installments(): void
    {
        $waiting = $this->layawayIn($this->organization, LayawayStatus::Completed);
        $delivered = $this->layawayIn($this->organization, LayawayStatus::Completed);
        $this->layawayIn($this->organization, LayawayStatus::Active);
        DB::table('layaways')->where('id', $delivered->getKey())->update(['delivered_at' => now()]);

        $codes = $this->withinOrganization($this->organization, fn (): array => (new CollectionFilters(CollectionPreset::CompletedNotDelivered))->query()->pluck('code')->all());

        $this->assertSame([$waiting->code], $codes);
    }

    public function test_completed_not_delivered_at_the_exact_boundaries_of_the_business_day(): void
    {
        $at = [];

        foreach (['2026-09-10 23:59:59', '2026-09-11 23:59:59', '2026-09-11 00:00:00', '2026-09-12 00:00:00'] as $instant) {
            $layaway = $this->layawayIn($this->organization, LayawayStatus::Completed);
            DB::table('layaways')->where('id', $layaway->getKey())->update([
                'completed_at' => CarbonImmutable::parse($instant, 'Asia/Tokyo')->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s'),
            ]);
            $at[$instant] = $layaway->code;
        }

        $day = CarbonImmutable::parse('2026-09-11', 'Asia/Tokyo');
        $codes = $this->withinOrganization($this->organization, fn (): array => (new CollectionFilters(CollectionPreset::CompletedNotDelivered, dueFrom: $day, dueTo: $day))->query()->pluck('code')->all());

        $this->assertSame([$at['2026-09-11 00:00:00'], $at['2026-09-11 23:59:59']], $codes);
    }

    public function test_overdue_layaways_are_part_of_the_portfolio(): void
    {
        $this->installment($this->layawayIn($this->organization, LayawayStatus::Overdue), 1, '2026-08-01');
        $this->installment($this->layawayIn($this->organization, LayawayStatus::PendingDownPayment), 1, '2026-08-02');

        $this->assertSame(['2026-08-01'], $this->dueDates(new CollectionFilters));
        $this->assertSame(['2026-08-01'], $this->dueDates(new CollectionFilters(CollectionPreset::OverdueOver15Days)));
    }

    public function test_covered_installments_and_terminal_layaways_are_left_out(): void
    {
        $active = $this->layawayIn($this->organization, LayawayStatus::Active);
        $cancelled = $this->layawayIn($this->organization, LayawayStatus::Cancelled);
        $this->installment($active, 1, '2026-09-11', paid: 3_000);
        $this->installment($active, 2, '2026-09-12');
        $this->installment($cancelled, 1, '2026-09-13');

        $this->assertSame(['2026-09-12'], $this->dueDates(new CollectionFilters));
        $this->assertSame(['2026-09-13'], $this->dueDates(new CollectionFilters(statuses: [LayawayStatus::Cancelled])));
    }

    public function test_each_organization_uses_its_own_grace(): void
    {
        $patient = $this->subscribe(Organization::factory()->create(['timezone' => 'Asia/Tokyo']));
        $this->withinOrganization($patient, fn () => OrganizationSetting::factory()->forKey(SettingKey::GracePeriodDays, 10)->create());
        app(Settings::class)->forget($patient);
        $this->installment($this->layawayIn($this->organization, LayawayStatus::Active), 1, '2026-08-20');
        $this->installment($this->layawayIn($patient, LayawayStatus::Active), 1, '2026-08-20');

        $this->assertSame(['2026-08-20'], $this->dueDates(new CollectionFilters(CollectionPreset::OverdueOver15Days)));
        $this->assertSame([], $this->withinOrganization($patient, fn (): array => $this->datesOf(new CollectionFilters(CollectionPreset::OverdueOver15Days))));
    }

    public function test_range_catalog_category_and_customer_narrow_the_list(): void
    {
        [$category, $catalog] = $this->withinOrganization($this->organization, fn (): array => [Category::factory()->create(), Catalog::factory()->create()]);
        $product = $this->withinOrganization($this->organization, fn (): Product => Product::factory()->create(['category_id' => $category->getKey()]));
        $ours = $this->layawayIn($this->organization, LayawayStatus::Active, $product);
        $other = $this->layawayIn($this->organization, LayawayStatus::Active);
        DB::table('layaways')->where('id', $ours->getKey())->update(['catalog_id' => $catalog->getKey()]);
        DB::table('customers')->where('id', $ours->customer_id)->update(['full_name' => 'María José González', 'document_number' => 'V12345678', 'phone' => '+584121112233', 'email' => 'maria@example.com']);
        $this->installment($ours, 1, '2026-09-20');
        $this->installment($other, 1, '2026-09-21');

        $this->assertSame(['2026-09-20'], $this->dueDates(new CollectionFilters(dueTo: CarbonImmutable::parse('2026-09-20'))));
        $this->assertSame(['2026-09-21'], $this->dueDates(new CollectionFilters(dueFrom: CarbonImmutable::parse('2026-09-21'))));
        $this->assertSame(['2026-09-20'], $this->dueDates(new CollectionFilters(catalogId: (int) $catalog->getKey())));
        $this->assertSame(['2026-09-20'], $this->dueDates(new CollectionFilters(categoryId: (int) $category->getKey())));

        foreach (['12.345.678', 'V-12345678', '0412-111.22.33', '+58 412 1112233', 'MARIA@example.com', 'gonzález maría'] as $term) {
            $this->assertSame(['2026-09-20'], $this->dueDates(new CollectionFilters(search: $term)), "Buscando «{$term}».");
        }
    }

    public function test_an_inverted_range_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CollectionFilters(dueFrom: CarbonImmutable::parse('2026-09-20'), dueTo: CarbonImmutable::parse('2026-09-19'));
    }

    private function installment(Layaway $layaway, int $number, string $due, int $paid = 0): void
    {
        DB::table('installments')->insert([
            'organization_id' => $layaway->organization_id,
            'layaway_id' => $layaway->getKey(),
            'number' => $number,
            'due_date' => $due,
            'amount_cents' => 3_000,
            'paid_cents' => $paid,
            'status' => $paid >= 3_000 ? 'paid' : 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function dueDates(CollectionFilters $filters): array
    {
        return $this->withinOrganization($this->organization, fn (): array => $this->datesOf($filters));
    }

    /**
     * @return list<string>
     */
    private function datesOf(CollectionFilters $filters): array
    {
        return $filters->installments()->get()->map(fn (Installment $installment): string => $installment->due_date->toDateString())->all();
    }
}
