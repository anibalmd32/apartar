<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Account\PortalContext;
use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Layaway\DueCalendar;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\NextPayment;
use App\Domain\Payment\LayawayReceipts;
use App\Domain\Payment\PaymentAccountView;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Tenancy\OrganizationBrand;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Http\Resources\InstallmentRowResource;
use App\Http\Resources\LayawaySummaryResource;
use App\Http\Resources\PaymentRowResource;
use App\Http\Resources\ReceiptRowResource;
use App\Models\Catalog;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\Receipt;
use App\Queries\ActivePaymentAccountsQuery;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El detalle de un apartado en el portal (`REQ-LAY-021`).
 *
 * ## Por `code`, y solo si es de la persona
 *
 * `PortalContext::findLayaway()` busca el código **entre las fichas de la
 * sesión** (RB-1): uno de otra persona y uno que no existe son el mismo `404`
 * (SP-1). No hay ruta que acepte un identificador de cliente.
 *
 * ## Qué se enseña según el estado
 *
 *  - **Esperando la inicial** (RB-6): no hay cronograma —todavía no existe—,
 *    sino cuánto es la inicial, hasta cuándo y dónde pagarla.
 *  - **Vivo** (RB-4, RB-7): el cronograma con sus días en el calendario del
 *    negocio, el próximo pago sugerido —la cuota más antigua sin cubrir, o el
 *    saldo para liquidar (RB-9)— y las cuentas de cobro, **siempre** que el
 *    apartado acepte pagos.
 *  - **Terminal** (SP-3, SP-4): solo lectura. Cancelado se dice con un texto
 *    **neutro**: el motivo que escribió el negocio no sale de aquí
 *    (`REQ-LAY-009` RB-8), y por eso este controlador no lo lee.
 *
 * El botón de subir comprobante lleva al formulario de `T-059` mientras el
 * apartado cobre; el chat es de H7. **El historial** (`REQ-LAY-022`, `T-061`)
 * son los pagos con su tasa congelada y los comprobantes con su estado, del
 * más reciente al más antiguo; todo de este apartado, que ya es de la persona. El pago sugerido sale de `NextPayment`, el mismo que precarga el
 * importe del formulario.
 */
final class LayawayShowController extends Controller
{
    use ProjectsMoney;

    public function __construct(
        private readonly TenantContext $context,
        private readonly Settings $settings,
        private readonly LimitEnforcer $limits,
        private readonly ActivePaymentAccountsQuery $paymentAccounts,
        private readonly LayawayReceipts $receipts,
    ) {}

    public function __invoke(Request $request, string $code, PortalContext $portal): Response
    {
        $layaway = $portal->findLayaway($code);
        $organization = $this->context->organization();
        $timezone = new DateTimeZone($organization->timezone);
        $calendar = DueCalendar::of($organization, $this->settings);
        $installments = $layaway->installments()->get();

        return Inertia::render('portal/LayawayShow', [
            'business' => OrganizationBrand::of($organization, $this->limits->hasFeature(PlanFeature::Branding))->toArray(),
            'layaway' => [
                ...(new LayawaySummaryResource($layaway))->resolve($request),
                'downPayment' => $this->money($layaway->terms()->totalDownPayment()),
                // Un plazo vencido no se enseña como plazo: o lo protege un
                // comprobante en revisión (SP-2) o el job lo expirará.
                'expiresAt' => $layaway->status === LayawayStatus::PendingDownPayment && ! $this->windowClosed($layaway)
                    ? $layaway->expires_at?->setTimezone($timezone)->locale('es')->isoFormat('D [de] MMMM [a las] h:mm a')
                    : null,
                'activatedAt' => $layaway->activated_at?->setTimezone($timezone)->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
                'statusMessage' => $this->statusMessage($layaway),
            ],
            'installments' => $installments
                ->map(fn (Installment $installment): array => (new InstallmentRowResource($installment, $calendar))->resolve($request))
                ->values()
                ->all(),
            'nextPaymentSuggestion' => $this->suggestion($layaway, $installments->all()),
            // RB-7 · dónde pagar, siempre que el apartado acepte pagos.
            'paymentAccounts' => $layaway->status->acceptsPayments()
                ? array_map(fn (PaymentAccountView $account): array => $account->toArray(), $this->paymentAccounts->all())
                : [],
            'catalogUrl' => $this->catalogUrl($layaway),
            // `REQ-PAY-001` RB-1 · solo si el apartado todavía cobra (SP-4).
            'receiptUrl' => $layaway->status->acceptsPayments() ? route('portal.receipts.create', ['code' => $layaway->code]) : null,
            // `REQ-LAY-022` · el historial. Un apartado tiene tantos pagos como
            // cuotas más alguno, así que va entero y sin paginar (SP-5).
            'payments' => Payment::query()
                ->where('layaway_id', $layaway->getKey())
                ->with(['allocations.installment', 'receipt'])
                ->latest('settled_at')
                ->latest('id')
                ->get()
                ->map(fn (Payment $payment): array => (new PaymentRowResource($payment, $timezone))->resolve($request))
                ->values()
                ->all(),
            'receipts' => Receipt::query()
                ->where('layaway_id', $layaway->getKey())
                ->with(['paymentAccount', 'replaces'])
                ->latest('created_at')
                ->latest('id')
                ->get()
                ->map(fn (Receipt $receipt): array => (new ReceiptRowResource($receipt, $timezone, CarbonImmutable::now()))->resolve($request))
                ->values()
                ->all(),
        ]);
    }

    /**
     * RB-9 · cuánto pagar ahora: la inicial, o la cuota más antigua sin
     * cubrir; y, aparte, el saldo entero para quien quiera liquidar.
     *
     * @param  list<Installment>  $installments
     * @return array<string, mixed>|null
     */
    private function suggestion(Layaway $layaway, array $installments): ?array
    {
        $next = NextPayment::for($layaway, $installments);

        return $next === null ? null : [
            'kind' => $next->kind->value,
            'installmentNumber' => $next->installmentNumber,
            'amount' => $this->money($next->amount),
            'payOff' => $this->money($layaway->remaining_cents),
        ];
    }

    /**
     * SP-3 y SP-4 · lo que se le dice al cliente de un apartado terminal.
     * Cancelado es **neutro**: el motivo del negocio no viaja.
     *
     * SP-2 (`T-059`) · esperando la inicial con el plazo ya vencido y un
     * comprobante en revisión, la reserva **está protegida**
     * (`REQ-LAY-006` RB-2: el job no la expira), y eso es lo que hay que
     * decirle a quien ve pasada la fecha que se le dio.
     */
    private function statusMessage(Layaway $layaway): ?string
    {
        return match ($layaway->status) {
            LayawayStatus::Cancelled => 'Este apartado fue cancelado por el negocio. Si tienes dudas, escríbele.',
            LayawayStatus::Expired => 'Este apartado venció porque la inicial no llegó a tiempo, y el producto volvió a la venta.',
            LayawayStatus::Completed => 'Este apartado está pagado. ¡Gracias!',
            LayawayStatus::PendingDownPayment => $this->windowClosed($layaway) && $this->receipts->holdExpiration($layaway)
                ? 'Tu comprobante está en revisión: tu reserva está protegida aunque haya pasado el plazo de la inicial.'
                : null,
            LayawayStatus::Active, LayawayStatus::Overdue => null,
        };
    }

    /**
     * Si ya pasó el plazo de la inicial (`expires_at`). Un instante, no un
     * día: lo mismo que mira el job de expiración.
     */
    private function windowClosed(Layaway $layaway): bool
    {
        return $layaway->expires_at !== null && $layaway->expires_at->isPast();
    }

    /**
     * RB-10, SP-9 · el catálogo de origen, solo si sigue abierto.
     */
    private function catalogUrl(Layaway $layaway): ?string
    {
        $catalog = $layaway->catalog_id === null ? null : Catalog::query()->find($layaway->catalog_id);

        if (! $catalog instanceof Catalog) {
            return null;
        }

        return in_array($catalog->visibility(), [CatalogVisibility::Listed, CatalogVisibility::Unlisted], true)
            ? route('catalog.show', ['catalog' => $catalog->slug])
            : null;
    }
}
