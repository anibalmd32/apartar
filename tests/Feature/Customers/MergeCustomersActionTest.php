<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Actions\Customer\MergeCustomersAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Customer\CannotMergeCustomerException;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\AuditEntry;
use App\Models\Customer;
use App\Models\CustomerMerge;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

/**
 * La fusión de dos fichas de la misma persona (`T-065`, `REQ-IDN-006`).
 */
final class MergeCustomersActionTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->member = $this->within(fn (): User => User::factory()->create());
    }

    /**
     * RB-2, RB-5, SP-5: todo lo de la absorbida pasa a la superviviente
     * —apartados, y con ellos sus pagos; comprobantes; enlaces de acceso—, los
     * apartados activos siguen su curso, la absorbida desaparece y queda el
     * rastro de adónde fue y la auditoría.
     */
    public function test_everything_moves_to_the_survivor_and_the_absorbed_record_goes(): void
    {
        [$survivor, $absorbed] = [$this->customer('V11111111'), $this->customer('V22222222')];
        $layaway = $this->layawayOf($absorbed, LayawayStatus::Active);
        $receipt = $this->receiptFor($layaway, attributes: ['customer_id' => $absorbed->getKey()]);
        $token = DB::table('customer_login_tokens')->insertGetId(['organization_id' => $this->organization->getKey(), 'customer_id' => $absorbed->getKey(), 'token_hash' => str_repeat('a', 64), 'requested_ip' => '127.0.0.1', 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);
        $balance = (array) DB::table('layaways')->where('id', $layaway->getKey())->first(['paid_cents', 'remaining_cents', 'status']);

        $summary = $this->merge($survivor, $absorbed);

        $this->assertSame([1, 1, 1, false], [$summary->layaways, $summary->receipts, $summary->loginTokens, $summary->accountMoves]);
        $this->assertSame($survivor->getKey(), (int) DB::table('layaways')->where('id', $layaway->getKey())->value('customer_id'));
        $this->assertSame($survivor->getKey(), (int) DB::table('receipts')->where('id', $receipt->getKey())->value('customer_id'));
        $this->assertSame($survivor->getKey(), (int) DB::table('customer_login_tokens')->where('id', $token)->value('customer_id'));
        $this->assertSame($balance, (array) DB::table('layaways')->where('id', $layaway->getKey())->first(['paid_cents', 'remaining_cents', 'status']), 'RB-6 · el balance del apartado no se recalcula.');
        $this->assertNull(DB::table('customers')->where('id', $absorbed->getKey())->first());
        $this->assertSame([$absorbed->getKey(), 'V22222222', $survivor->getKey()], array_values((array) DB::table('customer_merges')->first(['absorbed_customer_id', 'absorbed_document_number', 'survivor_customer_id'])));

        $entry = $this->within(fn (): AuditEntry => AuditEntry::query()->where('event', AuditEvent::CustomerMerged->value)->sole());
        $this->assertSame($this->member->getKey(), $entry->causer_id);
        $this->assertSame(1, $entry->properties?->toArray()['changed']['moved']['after']['layaways'] ?? null);
    }

    /**
     * RB-4: si solo la absorbida está vinculada, el vínculo pasa; si lo están
     * las dos a cuentas distintas, no se fusiona (SP-2).
     */
    public function test_the_account_link_moves_or_blocks_the_merge(): void
    {
        // El modelo `Account` llega con las cuentas de consumidor (H6); la tabla existe desde H0.
        [$first, $second] = [$this->account('ana@example.com'), $this->account('beto@example.com')];
        [$survivor, $absorbed] = [$this->customer('V11111111'), $this->customer('V22222222', $first)];

        $this->assertTrue($this->merge($survivor, $absorbed)->accountMoves);
        $this->assertSame($first, (int) DB::table('customers')->where('id', $survivor->getKey())->value('account_id'));

        $other = $this->customer('V33333333', $second);
        $this->assertRefused('cuentas distintas', fn () => $this->merge($this->fresh($survivor), $other));
        $this->assertNotNull(DB::table('customers')->where('id', $other->getKey())->first());
    }

    /**
     * RB-1, RB-7, SP-1: consigo misma, de otro negocio o una que ya no existe
     * —una fusión anterior de la misma transacción se la llevó— no se fusionan.
     * Alguien de otro negocio no fusiona.
     */
    public function test_impossible_merges_are_refused(): void
    {
        [$survivor, $absorbed, $third] = [$this->customer('V11111111'), $this->customer('V22222222'), $this->customer('V44444444')];
        $foreign = app(TenantContext::class)->runFor($this->neighbourOrganization(), fn (): Customer => Customer::factory()->create());

        $this->assertRefused('consigo misma', fn () => $this->merge($survivor, $survivor));
        $this->assertRefused('tu propio negocio', fn () => $this->merge($survivor, $foreign));

        $this->assertRefused('ya no existe', fn () => $this->within(fn () => DB::transaction(function () use ($survivor, $absorbed, $third): void {
            app(MergeCustomersAction::class)->handle($survivor, $absorbed, $this->member);
            app(MergeCustomersAction::class)->handle($third, $absorbed, $this->member);
        })));
        $this->assertNotNull(DB::table('customers')->where('id', $absorbed->getKey())->first(), 'SP-6 · todo vuelve atrás.');

        $stranger = app(TenantContext::class)->runFor($this->neighbourOrganization(), fn (): User => User::factory()->create());
        $this->expectException(AuthorizationException::class);
        $this->within(fn () => app(MergeCustomersAction::class)->handle($survivor, $absorbed, $stranger));
    }

    /**
     * SP-6: un fallo a mitad —aquí, al borrar la absorbida— deja las dos fichas
     * como estaban: nada movido, ningún rastro de fusión.
     */
    public function test_a_failure_halfway_rolls_everything_back(): void
    {
        [$survivor, $absorbed] = [$this->customer('V11111111'), $this->customer('V22222222')];
        $layaway = $this->layawayOf($absorbed, LayawayStatus::Active);
        Customer::deleting(fn () => throw new RuntimeException('El disco se llenó.'));

        $this->assertInstanceOf(RuntimeException::class, $this->refusedWith(fn () => $this->merge($survivor, $absorbed)));

        $this->assertSame($absorbed->getKey(), (int) DB::table('layaways')->where('id', $layaway->getKey())->value('customer_id'));
        $this->assertSame(0, DB::table('customer_merges')->count());
    }

    /**
     * La cadena: si la superviviente de una fusión es absorbida después, lo
     * que apuntaba a ella apunta a la nueva; las dos absorbidas llevan a la
     * misma ficha.
     */
    public function test_a_chain_of_merges_points_to_the_last_survivor(): void
    {
        [$first, $second, $third] = [$this->customer('V11111111'), $this->customer('V22222222'), $this->customer('V33333333')];

        $this->merge($second, $first);
        $this->merge($third, $second);

        $this->assertSame([$third->getKey(), $third->getKey()], $this->within(fn (): array => [CustomerMerge::survivorOf((int) $first->getKey())?->getKey(), CustomerMerge::survivorOf((int) $second->getKey())?->getKey()]));
        // La tabla queda aplanada: ninguna fusión apunta a una ficha borrada.
        $this->assertSame([$third->getKey()], DB::table('customer_merges')->distinct()->pluck('survivor_customer_id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    /**
     * Un ciclo en `customer_merges` —que la fusión no escribe, pero una
     * edición a mano podría— no cuelga la petición: no hay superviviente.
     */
    public function test_a_cycle_of_merges_has_no_survivor(): void
    {
        [$one, $two] = [$this->customer('V11111111'), $this->customer('V22222222')];

        foreach ([[$one, $two], [$two, $one]] as [$absorbed, $survivor]) {
            DB::table('customer_merges')->insert(['organization_id' => $this->organization->getKey(), 'absorbed_customer_id' => $absorbed->getKey(), 'absorbed_document_number' => $absorbed->document_number, 'survivor_customer_id' => $survivor->getKey(), 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->assertNull($this->within(fn (): ?Customer => CustomerMerge::survivorOf((int) $one->getKey())));
    }

    // -------------------------------------------------------------------

    private function customer(string $document, ?int $account = null): Customer
    {
        return $this->within(fn (): Customer => Customer::factory()->create(['document_number' => $document, 'account_id' => $account]));
    }

    private function account(string $email): int
    {
        return DB::table('accounts')->insertGetId(['display_name' => $email, 'email' => $email, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function fresh(Customer $customer): Customer
    {
        return $this->within(fn (): Customer => Customer::query()->findOrFail($customer->getKey()));
    }

    private function layawayOf(Customer $customer, LayawayStatus $status): Layaway
    {
        $layaway = $this->layawayIn($this->organization, $status);
        $orphan = $layaway->customer_id;
        DB::table('layaways')->where('id', $layaway->getKey())->update(['customer_id' => $customer->getKey()]);
        DB::table('customers')->where('id', $orphan)->delete();

        return $layaway;
    }

    private function merge(Customer $survivor, Customer $absorbed): \App\Domain\Customer\MergeSummary
    {
        return $this->within(fn () => app(MergeCustomersAction::class)->handle($survivor, $absorbed, $this->member));
    }

    /**
     * @param  Closure(): mixed  $attempt
     */
    private function assertRefused(string $message, Closure $attempt): void
    {
        $exception = $this->refusedWith($attempt);

        $this->assertInstanceOf(CannotMergeCustomerException::class, $exception);
        $this->assertStringContainsString($message, $exception->getMessage());
    }

    /**
     * @param  Closure(): mixed  $attempt
     */
    private function refusedWith(Closure $attempt): \Throwable
    {
        try {
            $attempt();
        } catch (\Throwable $exception) {
            return $exception;
        }

        $this->fail('Tenía que haberse rechazado.');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function within(Closure $callback): mixed
    {
        return $this->withinOrganization($this->organization, $callback);
    }
}
