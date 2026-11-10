<?php

declare(strict_types=1);

namespace Tests\Feature\Safeguard;

use App\Actions\Payment\RecordSafeguardAction;
use App\Actions\Payment\SafeguardData;
use App\Domain\Safeguard\SafeguardDestination;
use App\Domain\Safeguard\SafeguardScope;
use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentSafeguard;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class RecordSafeguardActionTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private User $owner;

    private Layaway $layaway;

    private Catalog $catalog;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Receipt::DISK);
        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->owner = $this->tenant(fn (): User => User::factory()->owner()->create());
        $this->layaway = $this->layawayIn($this->organization);
        $this->catalog = $this->tenant(fn (): Catalog => Catalog::factory()->create(['safeguard_enabled' => true]));
        DB::table('layaways')->where('id', $this->layaway->getKey())->update(['catalog_id' => $this->catalog->getKey()]);
        $this->layaway->refresh();
        $this->payment = $this->tenant(fn (): Payment => Payment::factory()->create(['layaway_id' => $this->layaway->getKey()]));
    }

    public function test_guarda_donde_quedo_el_pago_con_su_referencia_su_nota_y_su_comprobante(): void
    {
        $safeguard = $this->record(new SafeguardData(SafeguardDestination::BinanceUsdt, 'ORD-991', UploadedFile::fake()->image('binance.png'), 'Cambiado el mismo día'));

        $this->assertSame(SafeguardDestination::BinanceUsdt, $safeguard->destination);
        $this->assertSame('ORD-991', $safeguard->reference);
        $this->assertSame('Cambiado el mismo día', $safeguard->note);
        $this->assertSame($this->owner->getKey(), $safeguard->recorded_by);
        $this->assertStringStartsWith('resguardos/'.$this->organization->getKey().'/', (string) $safeguard->proof_path);
        Storage::disk(Receipt::DISK)->assertExists((string) $safeguard->proof_path);
    }

    public function test_editarlo_reemplaza_el_comprobante_anterior_y_no_crea_otro_resguardo(): void
    {
        $first = $this->record(new SafeguardData(SafeguardDestination::Zelle, null, UploadedFile::fake()->image('a.png')));
        $old = (string) $first->proof_path;

        $second = $this->record(new SafeguardData(SafeguardDestination::CashUsd, null, UploadedFile::fake()->image('b.png')));
        $kept = $this->record(new SafeguardData(SafeguardDestination::CashUsd, 'sobre 3'));

        $this->assertSame(1, DB::table('payment_safeguards')->count());
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame($second->proof_path, $kept->proof_path);
        $this->assertSame('sobre 3', $kept->reference);
        Storage::disk(Receipt::DISK)->assertMissing($old);
    }

    public function test_solo_aplica_a_los_apartados_de_un_catalogo_con_el_resguardo_activo(): void
    {
        $this->assertTrue($this->tenant(fn (): bool => SafeguardScope::appliesTo($this->layaway)));

        DB::table('catalogs')->where('id', $this->catalog->getKey())->update(['safeguard_enabled' => false]);

        $this->assertFalse($this->tenant(fn (): bool => SafeguardScope::appliesTo($this->layaway)));
        $this->expectExceptionObject(new InvalidArgumentException('El resguardo se activa en el catálogo de este apartado.'));
        $this->record(new SafeguardData(SafeguardDestination::Zelle));
    }

    public function test_un_pago_anulado_no_lleva_resguardo(): void
    {
        DB::table('payments')->where('id', $this->payment->getKey())->update(['voided_at' => now(), 'voided_by' => $this->owner->getKey(), 'void_reason' => 'Pago registrado dos veces.']);
        $this->payment = $this->tenant(fn (): Payment => Payment::query()->withVoided()->findOrFail($this->payment->getKey()));

        $this->expectExceptionObject(new InvalidArgumentException('Un pago anulado no lleva resguardo.'));
        $this->record(new SafeguardData(SafeguardDestination::Zelle));
    }

    private function record(SafeguardData $data): PaymentSafeguard
    {
        return $this->tenant(fn (): PaymentSafeguard => app(RecordSafeguardAction::class)->handle($this->payment, $data, $this->owner));
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function tenant(\Closure $callback): mixed
    {
        return app(TenantContext::class)->runFor($this->organization, $callback);
    }
}
