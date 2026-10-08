<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Actions\Layaway\ExpireLayawaysAction;
use App\Actions\Layaway\ExtendDownPaymentWindowAction;
use App\Domain\Layaway\DownPaymentExtension;
use App\Domain\Layaway\LayawayStatus;
use App\Models\Layaway;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * La prórroga única tras un rechazo tardío (`REQ-LAY-006` RB-3, SP-2, SP-3).
 *
 * Quien la llamará es el rechazo del comprobante (`T-062`); aquí se llama
 * directamente con el instante del rechazo, que es lo que recibirá.
 */
final class ExtendDownPaymentWindowActionTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    /**
     * SP-2: plazo el 13/09 a las 10:00, rechazo a las 11:00 → hasta las 11:00
     * del 14/09, con `extended_at` marcado.
     */
    public function test_a_late_rejection_grants_24_hours_from_the_rejection(): void
    {
        $layaway = $this->pendingUntil('2026-09-13 10:00:00');

        $outcome = $this->extend($layaway, '2026-09-13 11:00:00');

        $this->assertSame(DownPaymentExtension::Granted, $outcome);
        $row = DB::table('layaways')->where('id', $layaway->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame('2026-09-14 11:00:00', $row->expires_at);
        $this->assertSame('2026-09-13 11:00:00', $row->extended_at);
        $this->assertSame('2026-09-14 11:00:00', $layaway->expires_at?->format('Y-m-d H:i:s'), 'El modelo de quien llama vuelve actualizado.');
    }

    /**
     * SP-3: una sola vez. El segundo rechazo tardío no prorroga, y el job lo
     * expira en su pasada siguiente.
     */
    public function test_a_second_extension_is_not_granted(): void
    {
        $layaway = $this->pendingUntil('2026-09-13 10:00:00');
        $this->extend($layaway, '2026-09-13 11:00:00');

        $outcome = $this->extend($layaway, '2026-09-14 12:00:00');

        $this->assertSame(DownPaymentExtension::AlreadyExtended, $outcome);
        $this->assertSame('2026-09-14 11:00:00', DB::table('layaways')->where('id', $layaway->getKey())->value('expires_at'));

        $this->withinOrganization($this->organization, fn () => app(ExpireLayawaysAction::class)->handle(CarbonImmutable::parse('2026-09-14 12:00:00')));
        $this->assertSame('expired', DB::table('layaways')->where('id', $layaway->getKey())->value('status'));
    }

    /**
     * RB-3: solo si la ventana ya había vencido. Un rechazo a tiempo deja el
     * plazo como estaba, y la prórroga queda disponible.
     */
    public function test_a_rejection_inside_the_window_needs_no_extension(): void
    {
        $layaway = $this->pendingUntil('2026-09-13 10:00:00');

        $outcome = $this->extend($layaway, '2026-09-12 18:00:00');

        $this->assertSame(DownPaymentExtension::WindowStillOpen, $outcome);
        $this->assertFalse($outcome->wasGranted());
        $row = DB::table('layaways')->where('id', $layaway->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame('2026-09-13 10:00:00', $row->expires_at);
        $this->assertNull($row->extended_at);
    }

    /**
     * El borde (`T-062`): un rechazo **en el segundo exacto** en que vence
     * todavía está dentro del plazo, como para la expiración
     * (`ExpireLayawaysAction`, `expires_at < now`). La prórroga queda
     * disponible para un rechazo posterior.
     */
    public function test_a_rejection_at_the_exact_expiry_is_still_inside_the_window(): void
    {
        $layaway = $this->pendingUntil('2026-09-13 10:00:00');

        $this->assertSame(DownPaymentExtension::WindowStillOpen, $this->extend($layaway, '2026-09-13 10:00:00'));
        $this->assertNull(DB::table('layaways')->where('id', $layaway->getKey())->value('extended_at'));
        $this->assertSame(DownPaymentExtension::Granted, $this->extend($layaway, '2026-09-13 10:00:01'));
    }

    /**
     * Un apartado que ya no espera la inicial no se prorroga.
     */
    public function test_a_layaway_not_awaiting_its_down_payment_is_not_extended(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Expired);

        $this->assertSame(DownPaymentExtension::NotAwaitingDownPayment, $this->extend($layaway, '2026-09-13 11:00:00'));
        $this->assertNull(DB::table('layaways')->where('id', $layaway->getKey())->value('extended_at'));
    }

    private function extend(Layaway $layaway, string $rejectedAt): DownPaymentExtension
    {
        return $this->withinOrganization(
            $this->organization,
            fn (): DownPaymentExtension => app(ExtendDownPaymentWindowAction::class)->handle($layaway, CarbonImmutable::parse($rejectedAt)),
        );
    }

    private function pendingUntil(string $expiresAt): Layaway
    {
        $layaway = $this->layawayIn($this->organization);
        DB::table('layaways')->where('id', $layaway->getKey())->update(['expires_at' => $expiresAt]);

        return $this->withinOrganization($this->organization, fn (): Layaway => Layaway::query()->findOrFail($layaway->getKey()));
    }
}
