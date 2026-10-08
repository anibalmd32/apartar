<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\TenantContext;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

/**
 * La bandeja de conciliación contra la organización vecina (`REQ-PAY-004`,
 * `REQ-ORG-002` RB-1, `REQ-QAX-008` RB-2).
 *
 * Cuatro consultas nuevas del tenant —las filas, el contador, los bancos y
 * las cuentas del filtro— y el badge compartido. Ninguna escribe un `where`
 * de organización: lo pone el global scope, y lo que se afirma aquí es que lo
 * pone en todas. El canal tiene su archivo, `ReceiptInboxChannelTest`.
 */
final class ReceiptInboxIsolationTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
    }

    /**
     * Ni sus filas, ni su cifra en el contador, ni sus bancos ni sus cuentas
     * en los filtros.
     */
    public function test_the_neighbour_receipts_do_not_reach_our_inbox(): void
    {
        $ours = $this->receiptFor($this->layawayIn($this->organization, LayawayStatus::Active), attributes: ['bank' => 'Banesco']);

        $neighbour = $this->subscribe($this->neighbourOrganization());
        $theirLayaway = $this->layawayIn($neighbour, LayawayStatus::Active);
        $this->receiptFor($theirLayaway, attributes: ['bank' => 'Banco del Vecino']);
        $this->receiptFor($theirLayaway, attributes: ['bank' => 'Banco del Vecino']);
        $this->withinOrganization($neighbour, fn (): PaymentAccount => PaymentAccount::factory()->create());
        $this->withoutOrganization();

        $this->actingAs($this->member)->get(route('receipts.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('rows', 1)
            ->where('rows.0.id', $ours->getKey())
            ->where('teamMember.pendingReceipts', 1)
            ->where('options.banks', ['Banesco'])
            ->where('options.paymentAccounts', []));

        $this->getJson(route('receipts.pending'))->assertExactJson(['pending' => 1, 'newest' => $ours->getKey()]);
    }

    /**
     * Un filtro con la cuenta o el banco del vecino no es un filtro: se
     * ignora y se dice, como uno inventado, y no confirma que exista.
     */
    public function test_filtering_by_the_neighbour_account_or_bank_is_ignored(): void
    {
        $this->receiptFor($this->layawayIn($this->organization, LayawayStatus::Active));

        $neighbour = $this->subscribe($this->neighbourOrganization());
        $theirAccount = $this->withinOrganization($neighbour, fn (): PaymentAccount => PaymentAccount::factory()->create());
        $this->receiptFor($this->layawayIn($neighbour, LayawayStatus::Active), attributes: ['bank' => 'Banco del Vecino', 'payment_account_id' => $theirAccount->getKey()]);
        $this->withoutOrganization();

        $this->actingAs($this->member)
            ->get(route('receipts.index', ['account' => (string) $theirAccount->getKey(), 'bank' => 'Banco del Vecino']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('meta.ignoredFilters', ['bank', 'account'])
                ->has('rows', 1));
    }

    /**
     * El badge cuenta **la cola propia** y solo con el tenant propio fijado.
     * Con el de otro negocio —un miembro del equipo mirando el catálogo
     * público del vecino, con la sesión abierta— es `null`, no la cola ajena.
     */
    public function test_the_shared_badge_never_counts_another_organization(): void
    {
        $this->receiptFor($this->layawayIn($this->organization, LayawayStatus::Active));
        $neighbour = $this->subscribe($this->neighbourOrganization());
        $this->receiptFor($this->layawayIn($neighbour, LayawayStatus::Active));
        $this->receiptFor($this->layawayIn($neighbour, LayawayStatus::Active));

        $this->assertSame(1, $this->sharedBadge($this->organization));
        $this->assertNull($this->sharedBadge($neighbour));
    }

    /**
     * Un miembro desactivado no ve la cifra, ni con su propio tenant fijado:
     * desactivar no cierra su sesión, y el catálogo de su negocio no pasa por
     * `admin-active`.
     */
    public function test_a_disabled_member_gets_no_badge(): void
    {
        $this->receiptFor($this->layawayIn($this->organization, LayawayStatus::Active));
        $this->withinOrganization($this->organization, fn (): bool => $this->member->forceFill(['disabled_at' => now()])->save());

        $this->assertNull($this->sharedBadge($this->organization));
    }

    /**
     * Lo que `HandleInertiaRequests` compartiría con `$tenant` fijado.
     */
    private function sharedBadge(Organization $tenant): ?int
    {
        $request = Request::create('/');
        $request->setUserResolver(fn (): User => $this->member);
        $request->setLaravelSession(app('session.store'));

        return app(TenantContext::class)->runFor($tenant, function () use ($request): ?int {
            /** @var array{pendingReceipts: Closure(): ?int} $teamMember */
            $teamMember = app(HandleInertiaRequests::class)->share($request)['teamMember'];

            return ($teamMember['pendingReceipts'])();
        });
    }
}
