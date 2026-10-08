<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptStatus;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\PaymentAllocation;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

/**
 * Los modelos de la cobranza (`T-055`): `Payment` con su snapshot de tasa
 * inmutable (`REQ-FX-006` RB-3), `PaymentAllocation` que no se edita, y
 * `Receipt` con sus relaciones.
 */
final class PaymentModelTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    /**
     * `REQ-FX-006`: el snapshot sale de la base como se guardó —la tasa a ocho
     * decimales, en cadena, nunca `float`—.
     */
    public function test_a_payment_keeps_its_rate_snapshot_as_stored(): void
    {
        $payment = $this->within(fn (): Payment => Payment::factory()->createOne([
            'amount_cents_usd' => new Money(2_000),
            'declared_currency' => Currency::VES,
            'declared_amount' => '850.00',
            'rate_applied' => '42.5',
            'rate_source' => RateSource::Parallel,
        ]));

        $fresh = $this->within(fn (): Payment => Payment::query()->findOrFail($payment->getKey()));

        $this->assertSame(2_000, $fresh->amount_cents_usd->cents);
        $this->assertSame(Currency::VES, $fresh->declared_currency);
        $this->assertSame('850.00', $fresh->declared_amount);
        $this->assertSame('42.50000000', $fresh->rate_applied);
        $this->assertSame(RateSource::Parallel, $fresh->rate_source);
        $this->assertTrue($fresh->isManual());
    }

    /**
     * `REQ-FX-006` RB-3 y `REQ-PAY-009` RB-1: ningún campo del snapshot, ni el
     * importe, ni el apartado, ni el comprobante, ni la clave se actualizan.
     * La nota, sí.
     *
     * Se intenta **cada** columna de `IMMUTABLE_COLUMNS`, y el caso falla si
     * la lista y el conjunto probado dejan de coincidir: quitar una columna de
     * la guarda —`rate_published_at`, por ejemplo— lo pone rojo.
     */
    public function test_a_payment_is_immutable_except_its_note(): void
    {
        $payment = $this->within(fn (): Payment => Payment::factory()->createOne());
        $otherLayaway = $this->layawayIn($this->organization);
        $receipt = $this->receiptFor($otherLayaway);

        $changes = [
            'layaway_id' => $otherLayaway->getKey(),
            'receipt_id' => $receipt->getKey(),
            'payment_method' => PaymentMethod::EfectivoUsd,
            'payment_account_id' => $this->within(fn (): PaymentAccount => PaymentAccount::factory()->create())->getKey(),
            'paid_on' => now()->subDays(2)->toDateString(),
            'idempotency_key' => '6f1c2d3e-4a5b-4c6d-8e7f-9a0b1c2d3e4f',
            'amount_cents_usd' => new Money(1),
            'unallocated_cents' => new Money(1),
            'declared_currency' => Currency::VES,
            'declared_amount' => '1.00',
            'rate_applied' => '60.00000000',
            'rate_source' => RateSource::Manual,
            'rate_published_at' => now()->subDays(3),
            'settled_at' => now()->subYear(),
        ];

        $this->assertEqualsCanonicalizing(Payment::IMMUTABLE_COLUMNS, array_keys($changes), 'El caso tiene que intentar cada columna inmutable, ni más ni menos.');

        foreach ($changes as $column => $value) {
            try {
                $this->within(fn () => Payment::query()->findOrFail($payment->getKey())->forceFill([$column => $value])->save());
                $this->fail("Se actualizó {$column} de un pago.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString($column, $exception->getMessage());
            }
        }

        $this->within(fn () => Payment::query()->findOrFail($payment->getKey())->forceFill(['note' => 'Recibido por Ana.'])->save());
        $this->assertSame('Recibido por Ana.', $this->within(fn (): ?string => Payment::query()->findOrFail($payment->getKey())->note));
    }

    /**
     * `REQ-PAY-007` SP-5: dos pagos manuales con la misma clave en un negocio
     * chocan en la base; en dos negocios, no; y los que salen de un
     * comprobante, sin clave, no chocan entre sí.
     */
    public function test_the_idempotency_key_is_unique_per_organization(): void
    {
        $key = '6f1c2d3e-4a5b-4c6d-8e7f-9a0b1c2d3e4f';
        $this->within(fn () => Payment::factory()->count(2)->create(['idempotency_key' => null]));
        $this->within(fn () => Payment::factory()->createOne(['idempotency_key' => $key]));
        $this->withinOrganization($this->neighbourOrganization(), fn () => Payment::factory()->createOne(['idempotency_key' => $key]));

        $this->expectException(QueryException::class);

        $this->within(fn () => Payment::factory()->createOne(['idempotency_key' => $key]));
    }

    /**
     * Un comprobante, un pago: aprobar dos veces el mismo choca en la base.
     */
    public function test_a_receipt_has_at_most_one_payment(): void
    {
        $receipt = $this->receiptFor($this->layawayIn($this->organization), ReceiptStatus::Approved);
        $payment = $this->within(fn (): Payment => Payment::factory()->createOne(['layaway_id' => $receipt->layaway_id, 'receipt_id' => $receipt->getKey()]));

        $this->assertSame((int) $receipt->getKey(), (int) $this->within(fn (): ?int => Receipt::query()->findOrFail($receipt->getKey())->payment?->receipt_id));
        $this->assertFalse($payment->isManual(), 'Un pago que sale de un comprobante no es manual (REQ-LAY-022 RB-7).');

        $this->expectException(QueryException::class);

        $this->within(fn () => Payment::factory()->createOne(['layaway_id' => $receipt->layaway_id, 'receipt_id' => $receipt->getKey()]));
    }

    /**
     * La reasignación borra y reescribe el reparto (`REQ-PAY-009`): una fila
     * no se edita, pero se puede borrar.
     */
    public function test_an_allocation_is_not_edited_but_can_be_deleted(): void
    {
        $allocation = $this->within(fn (): PaymentAllocation => PaymentAllocation::factory()->createOne());

        $this->assertSame(
            (int) $this->within(fn (): mixed => Payment::query()->findOrFail($allocation->payment_id)->layaway_id),
            (int) $this->within(fn (): mixed => Installment::query()->findOrFail($allocation->installment_id)->layaway_id),
            'La factory reparte entre cuotas del mismo apartado del pago (REQ-PAY-009 RB-3).',
        );

        $otherPayment = $this->within(fn (): Payment => Payment::factory()->createOne());
        $otherInstallment = $this->within(fn (): Installment => Installment::factory()->createOne(['layaway_id' => $otherPayment->layaway_id]));

        foreach (['amount_cents' => new Money(1), 'payment_id' => $otherPayment->getKey(), 'installment_id' => $otherInstallment->getKey()] as $column => $value) {
            try {
                $this->within(fn () => PaymentAllocation::query()->findOrFail($allocation->getKey())->forceFill([$column => $value])->save());
                $this->fail("Se editó {$column} de una asignación de pago.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString('no se edita', $exception->getMessage(), $column);
            }
        }

        $this->within(fn () => PaymentAllocation::query()->findOrFail($allocation->getKey())->delete());
        $this->assertSame(0, $this->within(fn (): int => PaymentAllocation::query()->count()));
    }

    /**
     * Las relaciones de un pago, de su reparto y de un comprobante.
     */
    public function test_the_relations_of_the_collection_models(): void
    {
        $reviewer = $this->within(fn (): User => User::factory()->create());
        $layaway = $this->layawayIn($this->organization);
        $rejected = $this->receiptFor($layaway, ReceiptStatus::Rejected, ['reviewed_by' => $reviewer->getKey()]);
        $retry = $this->receiptFor($layaway, attributes: ['replaces_receipt_id' => $rejected->getKey()]);
        $payment = $this->within(fn (): Payment => Payment::factory()->createOne(['layaway_id' => $layaway->getKey(), 'registered_by' => $reviewer->getKey()]));
        $allocation = $this->within(fn (): PaymentAllocation => PaymentAllocation::factory()->createOne(['payment_id' => $payment->getKey()]));

        $this->within(function () use ($layaway, $rejected, $retry, $payment, $allocation, $reviewer): void {
            $fresh = Layaway::query()->findOrFail($layaway->getKey());
            $this->assertSame([(int) $rejected->getKey(), (int) $retry->getKey()], $fresh->receipts()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
            $this->assertSame([(int) $payment->getKey()], $fresh->payments()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

            $receipt = Receipt::query()->findOrFail($rejected->getKey());
            $this->assertSame((int) $reviewer->getKey(), (int) $receipt->reviewer?->getKey());
            $this->assertSame([(int) $retry->getKey()], $receipt->retries()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
            $this->assertSame((int) $rejected->getKey(), (int) Receipt::query()->findOrFail($retry->getKey())->replaces?->getKey());
            $this->assertSame((int) $layaway->customer_id, (int) $receipt->customer?->getKey());

            $paid = Payment::query()->findOrFail($payment->getKey());
            $this->assertSame((int) $reviewer->getKey(), (int) $paid->registeredBy?->getKey());
            $this->assertSame([(int) $allocation->getKey()], $paid->allocations()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
            $this->assertSame(1, Installment::query()->findOrFail($allocation->installment_id)->allocations()->count());
        });
    }

    /**
     * `T-057`: el sobrante de un pago va entre cero y su importe, y lo hace
     * cumplir la base. Los dos bordes entran.
     *
     * @param  int  $unallocated  centavos sin asignar de un pago de $30
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unallocatedBounds')]
    public function test_the_unallocated_cents_stay_between_zero_and_the_amount(int $unallocated, bool $accepted): void
    {
        if (! $accepted) {
            $this->expectException(QueryException::class);
        }

        $payment = $this->within(fn (): Payment => Payment::factory()->createOne(['unallocated_cents' => new Money($unallocated)]));

        $this->assertSame($unallocated, $payment->unallocated_cents->cents);
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function unallocatedBounds(): array
    {
        return [
            'nada sin asignar' => [0, true],
            'todo sin asignar' => [3_000, true],
            'más que el pago' => [3_001, false],
            'negativo' => [-1, false],
        ];
    }

    /**
     * `T-057`: la columna no tiene valor por defecto. Un `INSERT` que se
     * olvida del sobrante falla en vez de declarar «sin sobrante».
     */
    public function test_a_payment_without_its_unallocated_cents_is_refused_by_the_database(): void
    {
        $layaway = $this->layawayIn($this->organization);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('unallocated_cents');

        DB::table('payments')->insert([
            'organization_id' => $this->organization->getKey(),
            'layaway_id' => $layaway->getKey(),
            'amount_cents_usd' => 3_000,
            'declared_currency' => 'USD',
            'declared_amount' => '30.00',
            'rate_applied' => '1.00000000',
            'rate_source' => 'bcv',
            'settled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  \Closure(): mixed  $callback
     */
    private function within(\Closure $callback): mixed
    {
        return $this->withinOrganization($this->organization, $callback);
    }
}
