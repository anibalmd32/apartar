<?php

declare(strict_types=1);

namespace Tests\Feature\Export;

use App\Domain\Exchange\RateSource;
use App\Domain\Export\ExportStatus;
use App\Domain\Export\ExportType;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptStatus;
use App\Jobs\GenerateExport;
use App\Models\ExportJob;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\Receipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PaymentsExportTest extends TestCase
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

    public function test_each_payment_carries_what_was_declared_the_rate_and_the_dollars(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        DB::table('customers')->where('id', $layaway->customer_id)->update(['full_name' => 'María Pérez', 'document_number' => 'V12345678']);

        $this->withinOrganization($this->organization, function () use ($layaway): void {
            $account = PaymentAccount::factory()->zelle()->create(['identifier' => 'cobros@moda.test']);
            $receipt = Receipt::factory()->create([
                'layaway_id' => $layaway->getKey(),
                'payment_method' => PaymentMethod::Zelle,
                'payment_account_id' => $account->getKey(),
                'bank' => 'Bank of America',
                'reference' => 'ZL-778899',
                'paid_at' => '2026-09-08',
                'declared_amount' => '45.00',
                'declared_currency' => Currency::USD,
                'status' => ReceiptStatus::Approved,
            ]);
            Payment::factory()->create([
                'layaway_id' => $layaway->getKey(),
                'receipt_id' => $receipt->getKey(),
                'amount_cents_usd' => new Money(4_500),
                'declared_amount' => '45.00',
                'declared_currency' => Currency::USD,
                'settled_at' => CarbonImmutable::parse('2026-09-08 15:30', 'America/Caracas'),
            ]);
            Payment::factory()->create([
                'layaway_id' => $layaway->getKey(),
                'payment_method' => PaymentMethod::EfectivoVes,
                'paid_on' => '2026-09-09',
                'amount_cents_usd' => new Money(2_500),
                'declared_amount' => '972.50',
                'declared_currency' => Currency::VES,
                'rate_applied' => '38.90000000',
                'rate_source' => RateSource::Parallel,
                'note' => 'Pagó en la tienda',
                'settled_at' => CarbonImmutable::parse('2026-09-09 10:00', 'America/Caracas'),
            ]);
        });
        $this->withoutOrganization();

        $neighbour = $this->subscribe(Organization::factory()->create());
        $this->withinOrganization($neighbour, fn () => Payment::factory()->create(['layaway_id' => $this->layawayIn($neighbour, LayawayStatus::Active)->getKey()]));
        $this->withoutOrganization();

        $export = $this->generate();
        $rows = $this->rows($export);

        $this->assertSame(ExportStatus::Completed, $export->status);
        $this->assertSame(2, $export->row_count);
        $this->assertSame([
            'Fecha del pago', 'Registrado el', 'Cliente', 'Documento', 'Apartado', 'Producto', 'Origen', 'Método', 'Cuenta de cobro',
            'Banco', 'Referencia', 'Moneda declarada', 'Monto declarado', 'Tasa aplicada (Bs./USD)', 'Fuente de la tasa', 'Monto (USD)', 'Nota',
        ], $rows['columns']);

        [$zelle, $cash] = $rows['data'];
        $this->assertSame(['2026-09-08', '2026-09-08 15:30', 'María Pérez', 'V-12.345.678', $layaway->code], array_slice($zelle, 0, 5));
        $this->assertSame(['Comprobante', 'Zelle', 'Zelle cobros@moda.test', 'Bank of America', 'ZL-778899', 'USD', 45.0, 1.0, 'BCV', 45.0], array_slice($zelle, 6, 10));
        $this->assertSame(['2026-09-09', 'Registro manual', PaymentMethod::EfectivoVes->label(), null, null, null, 'VES', 972.5, 38.9, 'Paralelo', 25.0, 'Pagó en la tienda'], [
            $cash[0], ...array_slice($cash, 6),
        ]);
    }

    public function test_the_type_is_offered_to_whoever_sees_the_collections(): void
    {
        $this->actingAs($this->member)->get('/backoffice/exportaciones')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('types', fn (Collection $types): bool => $types->contains(fn (array $type): bool => $type['value'] === 'payments' && $type['label'] === 'Pagos')));
    }

    private function generate(): ExportJob
    {
        $export = $this->withinOrganization($this->organization, fn (): ExportJob => ExportJob::factory()->create([
            'type' => ExportType::Payments,
            'filters' => [],
            'requested_by' => $this->member->getKey(),
        ]));
        $this->withoutOrganization();
        app()->call([new GenerateExport((int) $export->getKey(), (int) $export->organization_id), 'handle']);

        return $this->withinOrganization($this->organization, fn (): ExportJob => ExportJob::query()->findOrFail($export->getKey()));
    }

    /**
     * @return array{columns: list<mixed>, data: list<list<mixed>>}
     */
    private function rows(ExportJob $export): array
    {
        /** @var list<list<mixed>> $sheet */
        $sheet = IOFactory::load(Storage::disk(ExportJob::DISK)->path((string) $export->file_path))->getActiveSheet()->toArray(null, true, false, false);

        foreach ($sheet as $i => $row) {
            if ($i > 0 && array_filter($row, fn (mixed $cell): bool => $cell !== null && $cell !== '') === []) {
                return ['columns' => array_values(array_filter($sheet[$i + 1], fn (mixed $cell): bool => $cell !== null)), 'data' => array_slice($sheet, $i + 2)];
            }
        }

        $this->fail('El archivo no tiene la línea en blanco que separa la cabecera.');
    }
}
