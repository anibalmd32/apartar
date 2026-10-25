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
use App\Domain\Payment\PaymentNotApplicableException;
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
                'expiresAt' => $layaway->status === LayawayStatus::PendingDownPayment && ! $this->windowClosed($layaway)
                    ? $layaway->expires_at?->setTimezone($timezone)->locale('es')->isoFormat('D [de] MMMM [a las] h:mm a')
                    : null,
                'activatedAt' => $layaway->activated_at?->setTimezone($timezone)->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
                'statusMessage' => $organization->status->acceptsCustomerReceipts()
                    ? $this->statusMessage($layaway)
                    : PaymentNotApplicableException::CLOSED_MESSAGE,
            ],
            'installments' => $installments
                ->map(fn (Installment $installment): array => (new InstallmentRowResource($installment, $calendar))->resolve($request))
                ->values()
                ->all(),
            'nextPaymentSuggestion' => $this->suggestion($layaway, $installments->all()),
            'paymentAccounts' => $layaway->status->acceptsPayments()
                ? array_map(fn (PaymentAccountView $account): array => $account->toArray(), $this->paymentAccounts->all())
                : [],
            'catalogUrl' => $this->catalogUrl($layaway),
            'receiptUrl' => $layaway->status->acceptsPayments() && $organization->status->acceptsCustomerReceipts()
                ? route('portal.receipts.create', ['code' => $layaway->code])
                : null,
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

    private function windowClosed(Layaway $layaway): bool
    {
        return $layaway->expires_at !== null && $layaway->expires_at->isPast();
    }

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
