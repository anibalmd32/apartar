<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Payment\RejectionReasonCode;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Receipt;
use App\Models\User;
use App\Support\SignedFileUrl;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

/**
 * La bandeja de conciliación (`REQ-PAY-004`, `T-060`).
 *
 * El negocio está en `Asia/Tokyo` y la aplicación en Caracas: las fechas de
 * recepción del filtro son días **del negocio**, y el caso del rango usa una
 * hora en que los dos calendarios dicen días distintos. El reloj se congela
 * donde importa el «hace cuánto» (RB-7).
 *
 * Lo de otra organización —filas, contador, opciones de filtro y el canal—
 * está en `Tests\Feature\Tenancy\ReceiptInboxIsolationTest` y
 * `ReceiptInboxChannelTest`.
 */
final class ReceiptInboxPageTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-07 12:00:00');

        $this->organization = $this->subscribe(Organization::factory()->create(['timezone' => 'Asia/Tokyo']));
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * RB-4 · la cola de trabajo: los pendientes primero y, entre ellos, **el
     * más antiguo arriba**; después los revisados, del más reciente al más
     * antiguo. Dos del mismo segundo salen en el orden en que se crearon.
     *
     * Todo se crea **fuera** del orden cronológico —también los revisados—,
     * para que el `id` solo no pueda dar el orden esperado. Y como el motor
     * suele devolver dos empates en orden de clave primaria aunque nadie se lo
     * pida, el desempate se afirma además en la propia consulta.
     */
    public function test_pending_receipts_come_first_with_the_oldest_on_top(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);

        $oneHour = $this->receiptAt($layaway, '-1 hour');
        $rejected = $this->receiptAt($layaway, '-10 hours', ReceiptStatus::Rejected);
        $threeHours = $this->receiptAt($layaway, '-3 hours');
        $twoHours = $this->receiptAt($layaway, '-2 hours');
        $approved = $this->receiptAt($layaway, '-30 minutes', ReceiptStatus::Approved);
        $twoHoursToo = $this->receiptAt($layaway, '-2 hours');

        $this->actingAs($this->member);
        $orders = [];
        DB::listen(function (QueryExecuted $query) use (&$orders): void {
            if (str_starts_with($query->sql, 'select * from `receipts`')) {
                $orders[] = $query->sql;
            }
        });

        $this->assertSame(
            [$threeHours->getKey(), $twoHours->getKey(), $twoHoursToo->getKey(), $oneHour->getKey(), $approved->getKey(), $rejected->getKey()],
            $this->idsFor([]),
        );
        $this->assertStringEndsWith('`receipts`.`id` asc limit 26 offset 0', $orders[0]);

        $this->get(route('receipts.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('backoffice/receipts/Inbox')
            ->where('teamMember.pendingReceipts', 4)
            ->where('newestPending', $twoHoursToo->getKey()));
    }

    /**
     * RB-4 dice «por defecto»: se puede pedir otro orden, por llegada o por
     * fecha de pago y en los dos sentidos, siempre con el `id` detrás. Lo que
     * no está en la lista se ignora y vuelve la cola.
     */
    public function test_another_order_can_be_asked_for(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);

        $old = $this->receiptAt($layaway, '-3 hours', attributes: ['paid_at' => '2026-10-07']);
        $reviewed = $this->receiptAt($layaway, '-2 hours', ReceiptStatus::Approved, ['paid_at' => '2026-10-01']);
        $new = $this->receiptAt($layaway, '-1 hour', attributes: ['paid_at' => '2026-10-04']);

        $this->actingAs($this->member);

        $this->assertSame([$new->getKey(), $reviewed->getKey(), $old->getKey()], $this->idsFor(['sort' => '-submitted']));
        $this->assertSame([$old->getKey(), $reviewed->getKey(), $new->getKey()], $this->idsFor(['sort' => 'submitted']));
        $this->assertSame([$reviewed->getKey(), $new->getKey(), $old->getKey()], $this->idsFor(['sort' => 'paid_at']));
        $this->assertSame([$old->getKey(), $new->getKey(), $reviewed->getKey()], $this->idsFor(['sort' => 'amount']));

        $this->get(route('receipts.index', ['sort' => '-paid_at']))->assertInertia(fn (AssertableInertia $page) => $page->where('meta.sort', '-paid_at'));
        $this->get(route('receipts.index', ['sort' => 'amount']))->assertInertia(fn (AssertableInertia $page) => $page->where('meta.sort', null));
    }

    /**
     * Las opciones de los filtros no viajan en la recarga parcial de la tabla
     * (`REQ-BOF-045` RB-2): ni se mandan ni se calculan —la consulta de los
     * bancos recorre todos los comprobantes del negocio—.
     */
    public function test_a_partial_reload_does_not_rebuild_the_filter_options(): void
    {
        $this->receiptAt($this->layawayIn($this->organization, LayawayStatus::Active), '-1 hour');
        $this->actingAs($this->member);
        $version = $this->get(route('receipts.index'))->viewData('page')['version'] ?? '';

        $banks = 0;
        DB::listen(function (QueryExecuted $query) use (&$banks): void {
            $banks += str_contains($query->sql, 'distinct `bank`') ? 1 : 0;
        });

        $response = $this->get(route('receipts.index', ['page' => 1]), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'backoffice/receipts/Inbox',
            'X-Inertia-Partial-Data' => 'rows,meta,teamMember,newestPending',
        ]);

        $response->assertOk();
        $props = $response->json('props');
        $this->assertIsArray($props);
        $this->assertArrayNotHasKey('options', $props);
        $this->assertArrayHasKey('rows', $props);
        $this->assertSame(0, $banks);
    }

    /**
     * RB-7 · más de 48 h en revisión es atrasado: las 48 h justas todavía no,
     * un segundo más sí. Un revisado nunca está atrasado, por viejo que sea.
     */
    public function test_a_receipt_pending_for_more_than_48_hours_is_overdue(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);

        $justInTime = $this->receiptAt($layaway, '-48 hours');
        $late = $this->receiptAt($layaway, '-48 hours -1 second');
        $oldButReviewed = $this->receiptAt($layaway, '-10 days', ReceiptStatus::Approved);

        $rows = $this->rowsOf($this->actingAs($this->member)->get(route('receipts.index')));

        $this->assertFalse($rows[$justInTime->getKey()]['overdue']);
        $this->assertTrue($rows[$late->getKey()]['overdue']);
        $this->assertFalse($rows[$oldButReviewed->getKey()]['overdue']);
    }

    /**
     * RB-8 y SP-3 · al cancelar el apartado, su pendiente se rechaza con
     * `layaway_cancelled` y **sale de la cola**; por defecto la bandeja ni lo
     * enseña, y con `cancelled=1` aparece como rechazado.
     */
    public function test_cancelling_the_layaway_takes_its_receipt_out_of_the_queue(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $receipt = $this->receiptAt($layaway, '-1 hour');
        $other = $this->receiptAt($this->layawayIn($this->organization, LayawayStatus::Active), '-2 hours');
        $this->withoutOrganization();

        $this->actingAs($this->member)
            ->post(route('layaways.cancel', $layaway), ['reason' => 'No volvió a pagar desde agosto.'])
            ->assertRedirect();

        $this->assertSame([$other->getKey()], $this->idsFor([]));
        $this->get(route('receipts.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('teamMember.pendingReceipts', 1));

        $this->assertSame([$other->getKey(), $receipt->getKey()], $this->idsFor(['cancelled' => '1']));
        $this->get(route('receipts.index', ['cancelled' => '1']))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('meta.filters.cancelled', '1')
            ->where('rows.1.status', 'rejected')
            ->where('rows.1.layaway.cancelled', true));

        $this->assertSame(RejectionReasonCode::LayawayCancelled->value, DB::table('receipts')->where('id', $receipt->getKey())->value('rejection_reason_code'));
    }

    /**
     * RB-5 · lo que lleva cada fila: el apartado con su enlace, el cliente con
     * nombre y documento, método, banco, referencia, la cuenta declarada
     * —marcada si está desactivada (SP-8)—, el importe con su moneda, la fecha
     * declarada, cuándo llegó y la miniatura firmada; un PDF, como PDF (SP-5).
     */
    public function test_each_row_carries_what_the_business_needs_to_review_it(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        DB::table('customers')->where('id', $layaway->customer_id)->update(['full_name' => 'María González', 'document_number' => 'V12345678']);
        $account = $this->withinOrganization($this->organization, fn (): PaymentAccount => PaymentAccount::factory()->create(['is_active' => false]));

        $image = $this->receiptAt($layaway, '-1 hour', attributes: [
            'payment_account_id' => $account->getKey(),
            'bank' => 'Mercantil',
            'reference' => '00042',
            'paid_at' => '2026-10-06',
            'declared_amount' => '1275.50',
        ]);
        $pdf = $this->receiptAt($layaway, '-30 minutes', attributes: [
            'payment_method' => PaymentMethod::EfectivoUsd,
            'bank' => null,
            'reference' => null,
            'declared_currency' => 'USD',
            'file_mime' => 'application/pdf',
        ]);

        $rows = $this->rowsOf($this->actingAs($this->member)->get(route('receipts.index')));

        $row = $rows[$image->getKey()];
        $this->assertSame(['code' => $layaway->code, 'url' => route('layaways.show', $layaway), 'cancelled' => false], $row['layaway']);
        $this->assertSame(['fullName' => 'María González', 'documentNumber' => 'V12345678'], $row['customer']);
        $this->assertSame(['pago_movil', 'Pago móvil', 'Mercantil', '00042'], [$row['method'], $row['methodLabel'], $row['bank'], $row['reference']]);
        $this->assertSame([$account->getKey(), false], [$row['paymentAccount']['id'], $row['paymentAccount']['isActive']]);
        $this->assertSame(['1275.50', 'VES', '2026-10-06'], [$row['declaredAmount'], $row['declaredCurrency'], $row['paidAt']]);
        $this->assertSame(['pending_review', 'En revisión'], [$row['status'], $row['statusLabel']]);
        $this->assertSame(CarbonImmutable::now()->subHour()->toIso8601String(), $row['submittedAt']);
        $this->get(route('receipts.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('now', CarbonImmutable::now()->toIso8601String()));
        $this->assertSame('image', $row['file']['kind']);
        $this->assertSame(SignedFileUrl::forBackoffice($image), $row['file']['url']);
        $this->assertSame(route('receipts.show', $image), $row['reviewUrl']);

        $this->assertSame('pdf', $rows[$pdf->getKey()]['file']['kind']);
        $this->assertNull($rows[$pdf->getKey()]['paymentAccount']);
        $this->assertSame([null, null, 'USD'], [$rows[$pdf->getKey()]['bank'], $rows[$pdf->getKey()]['reference'], $rows[$pdf->getKey()]['declaredCurrency']]);
    }

    /**
     * La miniatura abre de verdad: la URL de la fila sirve el archivo al
     * equipo (`REQ-PAY-002`, la ruta de `T-059`).
     */
    public function test_the_thumbnail_url_serves_the_file(): void
    {
        $receipt = $this->receiptAt($this->layawayIn($this->organization, LayawayStatus::Active), '-1 hour');
        \Illuminate\Support\Facades\Storage::fake(Receipt::DISK)->put($receipt->file_path, 'jpeg');

        $rows = $this->rowsOf($this->actingAs($this->member)->get(route('receipts.index')));

        $this->get($rows[$receipt->getKey()]['file']['url'])->assertOk();
    }

    /**
     * El contrato de [22]: estado, método, banco y cuenta de cobro, cada uno
     * contra su lista; lo que no se entiende se ignora **y se dice**
     * (`REQ-BOF-045` SP-4), y la pantalla repinta lo aplicado.
     */
    public function test_it_filters_by_status_method_bank_and_payment_account(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $account = $this->withinOrganization($this->organization, fn (): PaymentAccount => PaymentAccount::factory()->create());

        $banesco = $this->receiptAt($layaway, '-3 hours', attributes: ['bank' => 'Banesco']);
        $mercantil = $this->receiptAt($layaway, '-2 hours', attributes: ['bank' => 'Mercantil', 'payment_account_id' => $account->getKey()]);
        $cash = $this->receiptAt($layaway, '-1 hour', attributes: ['payment_method' => PaymentMethod::EfectivoUsd, 'bank' => null, 'reference' => null]);
        $approved = $this->receiptAt($layaway, '-4 hours', ReceiptStatus::Approved, ['bank' => 'Banesco']);

        $this->actingAs($this->member);

        $this->assertSame([$banesco->getKey(), $mercantil->getKey(), $cash->getKey()], $this->idsFor(['status' => 'pending_review']));
        $this->assertSame([$approved->getKey()], $this->idsFor(['status' => 'approved']));
        $this->assertSame([$cash->getKey()], $this->idsFor(['method' => 'efectivo_usd']));
        $this->assertSame([$banesco->getKey(), $approved->getKey()], $this->idsFor(['bank' => 'Banesco']));
        $this->assertSame([$mercantil->getKey()], $this->idsFor(['account' => (string) $account->getKey()]));

        $this->get(route('receipts.index', ['status' => 'paid', 'method' => 'cheque', 'bank' => 'Inventado', 'account' => '99999', 'cancelled' => 'si']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('meta.ignoredFilters', ['status', 'method', 'bank', 'account', 'cancelled'])
                ->where('meta.filters.status', null)
                ->has('rows', 4));

        $this->get(route('receipts.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('options.banks', ['Banesco', 'Mercantil'])
            ->has('options.statuses', 3)
            ->has('options.methods', count(PaymentMethod::cases()))
            ->where('options.paymentAccounts.0.id', $account->getKey()));
    }

    /**
     * El rango es de **recepción** y en días del negocio. Llegó a las 12:00 de
     * Caracas del 5, que son las 01:00 del **6** en Tokio: «desde el 6» lo
     * incluye y «hasta el 5» no. Una fecha que no existe se ignora.
     */
    public function test_the_date_range_is_in_days_of_the_business(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $receipt = $this->receiptFor($layaway, attributes: ['created_at' => CarbonImmutable::parse('2026-10-05 12:00:00', 'America/Caracas')]);

        $this->actingAs($this->member);

        $this->assertSame([$receipt->getKey()], $this->idsFor(['from' => '2026-10-06']));
        $this->assertSame([], $this->idsFor(['to' => '2026-10-05']));
        $this->assertSame([$receipt->getKey()], $this->idsFor(['from' => '2026-10-06', 'to' => '2026-10-06']));
        $this->assertSame([], $this->idsFor(['from' => '2026-10-07']));

        $this->get(route('receipts.index', ['from' => '2026-02-31', 'to' => 'ayer']))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('meta.ignoredFilters', ['from', 'to'])
            ->has('rows', 1));
    }

    /**
     * SP-4 · paginación de servidor **sin `count(*)` total**: la única cuenta
     * que se hace es la de los pendientes —el badge—, que va por su índice.
     */
    public function test_it_pages_without_counting_the_whole_inbox(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);

        for ($i = 0; $i < 26; $i++) {
            $this->receiptAt($layaway, sprintf('-%d minutes', 30 - $i));
        }

        $counts = [];
        DB::listen(function (QueryExecuted $query) use (&$counts): void {
            if (str_contains(strtolower($query->sql), 'count(')) {
                $counts[] = $query->sql;
            }
        });

        $this->actingAs($this->member)->get(route('receipts.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('rows', 25)
            ->where('meta.total', null)
            ->where('meta.lastPage', null)
            ->where('meta.hasMore', true));

        $receiptCounts = array_values(array_filter($counts, fn (string $sql): bool => str_contains($sql, '`receipts`')));
        $this->assertCount(1, $receiptCounts, 'La bandeja contó más que los pendientes (SP-4).');
        $this->assertStringContainsString('`status` = ?', $receiptCounts[0]);

        $this->get(route('receipts.index', ['page' => 2]))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('rows', 1)
            ->where('meta.hasMore', false));
    }

    /**
     * Sin `N+1`: el apartado, el cliente y la cuenta se cargan en una
     * consulta cada uno, sean cinco filas o veinte.
     */
    public function test_the_number_of_queries_does_not_grow_with_the_rows(): void
    {
        $this->actingAs($this->member);
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $account = $this->withinOrganization($this->organization, fn (): PaymentAccount => PaymentAccount::factory()->create());

        $this->receiptsFor($layaway, 5, $account);
        // La primera petición calienta lo que se recuerda por petición de
        // proceso —los ajustes y la tasa—; se mide la segunda.
        $this->queriesFor(route('receipts.index'));
        $few = $this->queriesFor(route('receipts.index'));

        $this->receiptsFor($this->layawayIn($this->organization, LayawayStatus::Active), 15, $account);
        $many = $this->queriesFor(route('receipts.index'));

        $this->assertSame($few, $many);
    }

    /**
     * RB-9 · con la organización suspendida la bandeja y su contador siguen
     * abiertos: aprobar comprobantes es lo que no se bloquea por impago.
     */
    public function test_it_works_with_the_organization_suspended(): void
    {
        $receipt = $this->receiptAt($this->layawayIn($this->organization, LayawayStatus::Active), '-1 hour');
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->actingAs($this->member)->get(route('receipts.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 1));
        $this->getJson(route('receipts.pending'))->assertOk()->assertExactJson(['pending' => 1, 'newest' => $receipt->getKey()]);
    }

    /**
     * SP-1 · el respaldo del tiempo real: el contador en JSON, para el sondeo
     * de 30 s, sin caché, con el pendiente más nuevo —lo que dice si llegó
     * algo—; y la misma cifra en cada navegación del backoffice, dentro de
     * `teamMember`.
     */
    public function test_the_pending_count_is_polled_and_travels_with_every_page(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        // El más nuevo es el **pendiente** creado el último, aunque diga una
        // hora más vieja: lo que importa es qué entró después de pintar la
        // tabla. Un revisado posterior no cuenta.
        $this->receiptAt($layaway, '-1 hour');
        $newest = $this->receiptAt($layaway, '-2 hours');
        $this->receiptAt($layaway, '-3 hours', ReceiptStatus::Approved);

        $response = $this->actingAs($this->member)->getJson(route('receipts.pending'));

        $response->assertOk()->assertExactJson(['pending' => 2, 'newest' => $newest->getKey()]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('teamMember.pendingReceipts', 2));
    }

    /**
     * Sin sesión del equipo no hay bandeja: al acceso, como el resto del
     * backoffice.
     */
    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get(route('receipts.index'))->assertRedirect(route('login'));
        $this->get(route('receipts.pending'))->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------

    /**
     * Un comprobante del apartado, llegado hace `$ago` respecto al reloj
     * congelado.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function receiptAt(Layaway $layaway, string $ago, ReceiptStatus $status = ReceiptStatus::PendingReview, array $attributes = []): Receipt
    {
        return $this->receiptFor($layaway, $status, ['created_at' => CarbonImmutable::now()->modify($ago), ...$attributes]);
    }

    private function receiptsFor(Layaway $layaway, int $count, PaymentAccount $account): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->receiptAt($layaway, sprintf('-%d minutes', $i + 1), attributes: ['payment_account_id' => $account->getKey()]);
        }
    }

    /**
     * Las consultas de una petición, contadas solo mientras dura.
     */
    private function queriesFor(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get($url)->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @param  array<string, string>  $query
     * @return list<int>
     */
    private function idsFor(array $query): array
    {
        $ids = [];

        $this->get(route('receipts.index', $query))->assertOk()->assertInertia(function (AssertableInertia $page) use (&$ids): void {
            $ids = array_map(fn (array $row): int => $row['id'], $page->toArray()['props']['rows']);
        });

        return $ids;
    }

    /**
     * @param  \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @return array<int, array<string, mixed>>
     */
    private function rowsOf(\Illuminate\Testing\TestResponse $response): array
    {
        $rows = [];

        $response->assertOk()->assertInertia(function (AssertableInertia $page) use (&$rows): void {
            foreach ($page->toArray()['props']['rows'] as $row) {
                $rows[$row['id']] = $row;
            }
        });

        return $rows;
    }
}
