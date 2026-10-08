<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Payment\PaymentAccountView;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptInboxSort;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Concerns\ResolvesTableState;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\PaymentAccount;
use App\Models\Receipt;
use App\Support\SignedFileUrl;
use App\Support\TableState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La bandeja de conciliación (`REQ-PAY-004`).
 *
 * Es la cola de trabajo del negocio: lo que han reportado sus clientes y
 * espera a que alguien lo mire. Por eso el orden **por defecto** es la cola:
 * los pendientes van primero y, entre ellos, **el más antiguo arriba** (RB-4).
 * Se puede pedir otro —por llegada o por fecha de pago, `ReceiptInboxSort`—,
 * pero no por importe: vienen en dos monedas sin convertir.
 *
 * ## Lo que hace `__invoke()`
 *
 *  - **Filtra** por estado, método, banco, cuenta de cobro y rango de fechas
 *    de recepción (contrato de [22]), y **oculta por defecto los apartados
 *    cancelados** (RB-8): sus pendientes ya salieron de la cola al cancelar
 *    (SP-3, `LayawayReceipts::rejectPendingOnCancellation()`), y lo que queda
 *    de ellos es historia. `cancelled=1` los enseña.
 *  - **Ordena**, sin `sort`, en FIFO los pendientes y del más reciente al más
 *    antiguo el resto; con `sort`, por la fecha pedida. Siempre con el `id`
 *    como desempate: dos comprobantes del mismo segundo salen en el orden en
 *    que se crearon, y la página siguiente no repite ni se salta a nadie.
 *  - **Pagina sin contar** (SP-4): `simplePaginate`, sin `count(*)` total. La
 *    bandeja de un negocio con un año de cobranza son miles de filas, y el
 *    total no ayuda a revisar la siguiente.
 *  - **Marca los atrasados** (RB-7): más de 48 h en revisión, contadas desde
 *    que llegó —no desde la fecha que declara el cliente, que es cuándo pagó—.
 *
 * Ningún `where` de organización: el global scope de `Receipt`, `Layaway`,
 * `Customer` y `PaymentAccount` lo pone, y es lo que hace que la misma URL
 * enseñe a cada equipo lo suyo (`REQ-ORG-002` RB-1).
 *
 * ## Lo que no hace todavía
 *
 * **La pantalla de revisión** (RB-6: el archivo ampliable, el cronograma, el
 * saldo, los pagos previos y lo que aplicaría FIFO) es de `T-061`, junto con
 * aprobar. Hasta entonces cada fila lleva a la vista del apartado —el
 * cronograma y el saldo ya están allí— y abre el archivo firmado.
 */
final class ReceiptInboxController extends Controller
{
    use ResolvesTableState;

    /** RB-7: a partir de aquí, un pendiente está atrasado. */
    public const OVERDUE_AFTER_HOURS = 48;

    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', Receipt::class);

        $state = $this->tableState($request, ReceiptInboxSort::whitelist());
        $receipts = $this->paginateTable($this->query($request, $state), $state, simple: true);
        $now = CarbonImmutable::now();

        return Inertia::render('backoffice/receipts/Inbox', [
            'rows' => $this->rows($receipts, $now),
            // El reloj con el que se marcaron los atrasados viaja con las
            // filas, y la pantalla cuenta la antigüedad con él: «hace 47 h»
            // y «atrasado» no pueden salir de dos relojes distintos.
            'now' => $now->toIso8601String(),
            'meta' => $this->tableMeta($receipts, $state),
            'newestPending' => $this->newestPendingId(),
            // Una *closure*: la recarga parcial de la tabla no la pide, y así
            // no vuelve a recorrer los bancos de todos los comprobantes en
            // cada cambio de página (`REQ-BOF-045` RB-2).
            'options' => fn (): array => [
                'statuses' => array_map(fn (ReceiptStatus $status): array => ['value' => $status->value, 'label' => $status->label()], ReceiptStatus::cases()),
                'methods' => array_map(fn (PaymentMethod $method): array => ['value' => $method->value, 'label' => $method->label()], PaymentMethod::cases()),
                'banks' => $this->banks(),
                'paymentAccounts' => $this->paymentAccounts(),
            ],
            'overdueAfterHours' => self::OVERDUE_AFTER_HOURS,
            'timezone' => $this->context->organization()->timezone,
        ]);
    }

    /**
     * Lo que sondea la pantalla cada 30 s mientras no haya tiempo real (RB-3,
     * SP-1): cuántos esperan y **el más nuevo de ellos**.
     *
     * La cifra es el badge; la misma viaja en cada navegación dentro de
     * `teamMember.pendingReceipts` (`HandleInertiaRequests`). El más nuevo es
     * lo que dice si **llegó** algo: la diferencia de dos recuentos no lo
     * dice, porque si en la misma media hora sale uno de la cola —se aprueba,
     * se cancela su apartado— y entra otro, la cifra no se mueve. Los `id`
     * crecen, así que uno mayor que el que pintó la tabla es uno que llegó
     * después.
     *
     * Nada más: repetir la tabla entera cada medio minuto en cada pestaña
     * abierta sería pagar por cien filas para saber si hay una nueva.
     */
    public function pending(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', Receipt::class);

        return response()->json([
            'pending' => Receipt::query()->pendingReview()->count(),
            'newest' => $this->newestPendingId(),
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * El `id` del pendiente más nuevo, o `null` si no hay ninguno. Por el
     * índice `(organization_id, status, created_at)`, que lleva la clave
     * primaria detrás: recorre solo los pendientes.
     */
    private function newestPendingId(): ?int
    {
        $id = Receipt::query()->pendingReview()->max('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * La consulta, con sus filtros resueltos y anotados en el estado.
     *
     * @param  TableState<*>  $state
     * @return Builder<Receipt>
     */
    private function query(Request $request, TableState $state): Builder
    {
        $query = Receipt::query()->with([
            'layaway:id,organization_id,code,status',
            'customer:id,organization_id,full_name,document_number',
            'paymentAccount',
        ]);

        $status = $state->filter('status', $request->query('status'), fn (mixed $raw): ?ReceiptStatus => is_string($raw) ? ReceiptStatus::tryFrom($raw) : null);

        if ($status instanceof ReceiptStatus) {
            $query->where('status', $status->value);
        }

        $method = $state->filter('method', $request->query('method'), fn (mixed $raw): ?PaymentMethod => is_string($raw) ? PaymentMethod::tryFrom($raw) : null);

        if ($method instanceof PaymentMethod) {
            $query->where('payment_method', $method->value);
        }

        $bank = $state->filter('bank', $request->query('bank'), fn (mixed $raw): ?string => $this->knownBank($raw));

        if ($bank !== null) {
            $query->where('bank', $bank);
        }

        $account = $state->filter('account', $request->query('account'), fn (mixed $raw): ?int => $this->ownAccountId($raw));

        if ($account !== null) {
            $query->where('payment_account_id', $account);
        }

        $this->applyReceivedRange($query, $request, $state);

        // RB-8: fuera los de apartados cancelados, salvo que se pidan. El
        // valor que se acepta es `1`; cualquier otro se ignora y se dice.
        $withCancelled = $state->filter('cancelled', $request->query('cancelled'), fn (mixed $raw): ?bool => $raw === '1' ? true : null);

        if ($withCancelled !== true) {
            $query->whereHas('layaway', fn (Builder $layaway) => $layaway->where('status', '!=', LayawayStatus::Cancelled->value));
        }

        $sort = $state->sort;

        if ($sort instanceof ReceiptInboxSort) {
            return $query->orderBy($sort->column(), $state->direction())->orderBy('receipts.id', $state->direction());
        }

        return $this->fifo($query);
    }

    /**
     * RB-4, el orden por defecto: los pendientes primero, del más antiguo al
     * más reciente; el resto, del más reciente al más antiguo. El `id`
     * desempata, así que el orden es total.
     *
     * Las dos expresiones `CASE` no usan índice: con miles de comprobantes el
     * motor ordena en memoria las filas del negocio. Es el precio de una cola
     * con dos sentidos en una sola consulta; si un día pesa, se parte en dos
     * consultas —pendientes por su índice, revisados por el suyo—.
     *
     * @param  Builder<Receipt>  $query
     * @return Builder<Receipt>
     */
    private function fifo(Builder $query): Builder
    {
        $pending = ReceiptStatus::PendingReview->value;

        return $query
            ->orderByRaw('CASE WHEN receipts.status = ? THEN 0 ELSE 1 END', [$pending])
            ->orderByRaw('CASE WHEN receipts.status = ? THEN receipts.created_at END ASC', [$pending])
            ->orderByDesc('receipts.created_at')
            ->orderBy('receipts.id');
    }

    /**
     * Un banco **de los que ya aparecen** en los comprobantes del negocio, o
     * `null`. Lo que no está en la lista no es un filtro, es un texto.
     */
    private function knownBank(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '' || mb_strlen($raw) > 100) {
            return null;
        }

        return Receipt::query()->where('bank', $raw)->exists() ? $raw : null;
    }

    /**
     * El identificador de una cuenta de cobro **de este negocio**, incluidas
     * las desactivadas (SP-8); `null` si no lo es. La de otro negocio no
     * existe bajo el global scope y se ignora igual que una inventada.
     */
    private function ownAccountId(mixed $raw): ?int
    {
        if (! is_string($raw) || ! ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }

        $id = PaymentAccount::query()->whereKey((int) $raw)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Rango de fechas de **recepción**, cada extremo por su cuenta, en días
     * del calendario del negocio (`REQ-ORG-006` RB-7): «desde el 5» empieza a
     * la medianoche del 5 **allí**, y «hasta el 5» incluye el 5 entero.
     *
     * La fecha se lee directamente en la zona del negocio y no en la de la
     * aplicación: leída en Caracas y llevada después a Tokio, la medianoche
     * del 5 sigue siendo el 5, pero llevada a una zona al oeste de Caracas
     * sería las nueve de la noche del 4.
     *
     * @param  Builder<Receipt>  $query
     * @param  TableState<*>  $state
     */
    private function applyReceivedRange(Builder $query, Request $request, TableState $state): void
    {
        $timezone = $this->context->organization()->timezone;
        $asDate = fn (CarbonImmutable $date): string => $date->toDateString();

        $from = $state->filter('from', $request->query('from'), fn (mixed $raw): ?CarbonImmutable => $this->dayIn($raw, $timezone), $asDate);

        if ($from instanceof CarbonImmutable) {
            $query->where('receipts.created_at', '>=', $from->startOfDay()->setTimezone(config('app.timezone')));
        }

        $to = $state->filter('to', $request->query('to'), fn (mixed $raw): ?CarbonImmutable => $this->dayIn($raw, $timezone), $asDate);

        if ($to instanceof CarbonImmutable) {
            $query->where('receipts.created_at', '<=', $to->endOfDay()->setTimezone(config('app.timezone')));
        }
    }

    /**
     * Un `AAAA-MM-DD` como día de esa zona, o `null` si no es una fecha que
     * exista: el 31 de febrero no se convierte en el 3 de marzo.
     */
    private function dayIn(mixed $raw, string $timezone): ?CarbonImmutable
    {
        if (! is_string($raw) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $parts) !== 1) {
            return null;
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $raw, $timezone);

        return $day instanceof CarbonImmutable ? $day : null;
    }

    /**
     * Las filas tal como las pinta la bandeja (RB-5).
     *
     * El cliente va con nombre y documento **aquí**, en la respuesta de la
     * sesión del equipo, y nunca en el evento difundido (RB-2). La miniatura
     * es la URL firmada del backoffice (`REQ-PAY-002`, 15 min); un PDF va con
     * su tipo para que la pantalla pinte el icono en vez de renderizarlo
     * (SP-5).
     *
     * @param  Paginator<int, Receipt>  $receipts
     * @return list<array<string, mixed>>
     */
    private function rows(Paginator $receipts, CarbonImmutable $now): array
    {
        $overdueBefore = $now->subHours(self::OVERDUE_AFTER_HOURS);

        return array_map(function (Receipt $receipt) use ($overdueBefore): array {
            $layaway = $receipt->layaway;
            $customer = $receipt->customer;
            $account = $receipt->paymentAccount;
            $pending = $receipt->status->awaitsReview();

            return [
                'id' => (int) $receipt->getKey(),
                'status' => $receipt->status->value,
                'statusLabel' => $receipt->status->label(),
                'overdue' => $pending && $receipt->created_at !== null && $receipt->created_at->lessThan($overdueBefore),
                'submittedAt' => $receipt->created_at?->toIso8601String(),
                'paidAt' => $receipt->paid_at->toDateString(),
                'layaway' => $layaway instanceof Layaway ? [
                    'code' => $layaway->code,
                    'url' => route('layaways.show', $layaway),
                    'cancelled' => $layaway->status === LayawayStatus::Cancelled,
                ] : null,
                'customer' => $customer instanceof Customer ? [
                    'fullName' => $customer->full_name,
                    'documentNumber' => $customer->document_number,
                ] : null,
                'method' => $receipt->payment_method->value,
                'methodLabel' => $receipt->payment_method->label(),
                'bank' => $receipt->bank,
                'reference' => $receipt->reference,
                'paymentAccount' => $account instanceof PaymentAccount ? PaymentAccountView::of($account)->toArray() : null,
                'declaredAmount' => (string) $receipt->declared_amount,
                'declaredCurrency' => $receipt->declared_currency->value,
                'file' => [
                    'url' => SignedFileUrl::forBackoffice($receipt),
                    'kind' => $receipt->file_mime === 'application/pdf' ? 'pdf' : 'image',
                ],
            ];
        }, $receipts->items());
    }

    /**
     * Los bancos que aparecen en los comprobantes del negocio, para el filtro.
     *
     * De los comprobantes y no de las cuentas de cobro: el banco que se filtra
     * es **desde el que** pagó el cliente, y ese lo escribe él.
     *
     * @return list<string>
     */
    private function banks(): array
    {
        /** @var list<string> $banks */
        $banks = Receipt::query()->whereNotNull('bank')->distinct()->orderBy('bank')->pluck('bank')->all();

        return $banks;
    }

    /**
     * Las cuentas de cobro del negocio, **incluidas las desactivadas** (SP-8):
     * un comprobante viejo sigue apuntando a la suya.
     *
     * @return list<array<string, mixed>>
     */
    private function paymentAccounts(): array
    {
        return PaymentAccount::query()
            ->orderBy('position')
            ->get()
            ->map(fn (PaymentAccount $account): array => PaymentAccountView::of($account)->toArray())
            ->values()
            ->all();
    }
}
