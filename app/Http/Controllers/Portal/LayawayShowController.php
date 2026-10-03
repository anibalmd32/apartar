<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Account\PortalContext;
use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Layaway\DueCalendar;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Money;
use App\Domain\Payment\PaymentAccountView;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Tenancy\OrganizationBrand;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Http\Resources\InstallmentRowResource;
use App\Http\Resources\LayawaySummaryResource;
use App\Models\Catalog;
use App\Models\Installment;
use App\Models\Layaway;
use App\Queries\ActivePaymentAccountsQuery;
use App\Support\Settings;
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
 * El botón de subir comprobante es de `T-059` (`receiptUrl`, hoy `null`), el
 * chat de H7 y el historial de pagos y comprobantes de `T-061`.
 */
final class LayawayShowController extends Controller
{
    use ProjectsMoney;

    public function __construct(
        private readonly TenantContext $context,
        private readonly Settings $settings,
        private readonly LimitEnforcer $limits,
        private readonly ActivePaymentAccountsQuery $paymentAccounts,
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
                'expiresAt' => $layaway->status === LayawayStatus::PendingDownPayment
                    ? $layaway->expires_at?->setTimezone($timezone)->locale('es')->isoFormat('D [de] MMMM [a las] h:mm a')
                    : null,
                'activatedAt' => $layaway->activated_at?->setTimezone($timezone)->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
                'statusMessage' => $this->statusMessage($layaway->status),
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
            'receiptUrl' => null,
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
        if (! $layaway->status->acceptsPayments()) {
            return null;
        }

        if ($layaway->status === LayawayStatus::PendingDownPayment) {
            return ['kind' => 'down_payment', 'installmentNumber' => null, 'amount' => $this->money($layaway->terms()->totalDownPayment()), 'payOff' => $this->money($layaway->remaining_cents)];
        }

        foreach ($installments as $installment) {
            $pending = $installment->amount_cents->cents - $installment->paid_cents->cents;

            if ($pending > 0) {
                return ['kind' => 'installment', 'installmentNumber' => $installment->number, 'amount' => $this->money(new Money($pending)), 'payOff' => $this->money($layaway->remaining_cents)];
            }
        }

        return null;
    }

    /**
     * SP-3 y SP-4 · lo que se le dice al cliente de un apartado terminal.
     * Cancelado es **neutro**: el motivo del negocio no viaja.
     */
    private function statusMessage(LayawayStatus $status): ?string
    {
        return match ($status) {
            LayawayStatus::Cancelled => 'Este apartado fue cancelado por el negocio. Si tienes dudas, escríbele.',
            LayawayStatus::Expired => 'Este apartado venció porque la inicial no llegó a tiempo, y el producto volvió a la venta.',
            LayawayStatus::Completed => 'Este apartado está pagado. ¡Gracias!',
            LayawayStatus::PendingDownPayment, LayawayStatus::Active, LayawayStatus::Overdue => null,
        };
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
