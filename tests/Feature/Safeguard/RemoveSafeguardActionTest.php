<?php

declare(strict_types=1);

namespace Tests\Feature\Safeguard;

use App\Actions\Payment\RecordSafeguardAction;
use App\Actions\Payment\RemoveSafeguardAction;
use App\Actions\Payment\SafeguardData;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Safeguard\SafeguardDestination;
use App\Domain\Tenancy\TenantContext;
use App\Models\AuditEntry;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class RemoveSafeguardActionTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private User $owner;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Receipt::DISK);
        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->owner = $this->tenant(fn (): User => User::factory()->owner()->create());
        $layaway = $this->layawayIn($this->organization);
        $catalog = $this->tenant(fn (): Catalog => Catalog::factory()->create(['safeguard_enabled' => true]));
        DB::table('layaways')->where('id', $layaway->getKey())->update(['catalog_id' => $catalog->getKey()]);
        $this->payment = $this->tenant(fn (): Payment => Payment::factory()->create(['layaway_id' => $layaway->getKey()]));
    }

    public function test_borra_el_resguardo_y_su_comprobante_y_lo_deja_en_la_auditoria(): void
    {
        $safeguard = $this->tenant(fn () => app(RecordSafeguardAction::class)->handle($this->payment, new SafeguardData(SafeguardDestination::Zelle, 'Z-9', UploadedFile::fake()->image('z.png')), $this->owner));

        $this->tenant(fn () => app(RemoveSafeguardAction::class)->handle($this->payment, $this->owner));

        $this->assertSame(0, DB::table('payment_safeguards')->count());
        Storage::disk(Receipt::DISK)->assertMissing((string) $safeguard->proof_path);
        $entry = $this->tenant(fn (): AuditEntry => AuditEntry::query()->audited()->ofEvent(AuditEvent::SafeguardRemoved)->sole());
        $this->assertSame($this->payment->getKey(), (int) $entry->subject_id);
        $this->assertSame('zelle', $entry->properties->get('changed')['destination']['before']);
    }

    public function test_sin_resguardo_no_hace_nada_y_otro_negocio_no_puede(): void
    {
        $this->tenant(fn () => app(RemoveSafeguardAction::class)->handle($this->payment, $this->owner));
        $this->assertSame(0, $this->tenant(fn (): int => AuditEntry::query()->audited()->ofEvent(AuditEvent::SafeguardRemoved)->count()));

        $stranger = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());
        $this->expectException(AuthorizationException::class);
        $this->tenant(fn () => app(RemoveSafeguardAction::class)->handle($this->payment, $stranger));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function tenant(Closure $callback): mixed
    {
        return app(TenantContext::class)->runFor($this->organization, $callback);
    }
}
