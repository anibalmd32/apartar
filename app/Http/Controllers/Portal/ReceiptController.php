<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Payment\SubmitReceiptAction;
use App\Domain\Account\PortalContext;
use App\Domain\Exchange\CurrentRate;
use App\Domain\Layaway\NextPayment;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\LayawayReceipts;
use App\Domain\Payment\PaymentAccountView;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\OrganizationBrand;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StoreReceiptRequest;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Http\Resources\LayawaySummaryResource;
use App\Models\Layaway;
use App\Models\Receipt;
use App\Queries\ActivePaymentAccountsQuery;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class ReceiptController extends Controller
{
    use ProjectsMoney;

    public const SUBMITTED_STATUS = 'Recibimos tu comprobante. Lo revisaremos pronto.';

    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
        private readonly ActivePaymentAccountsQuery $paymentAccounts,
        private readonly CurrentRate $rate,
        private readonly LayawayReceipts $layawayReceipts,
    ) {}

    public function create(Request $request, string $code, PortalContext $portal): Response
    {
        $layaway = $portal->findLayaway($code);

        abort_unless($layaway->status->acceptsPayments(), 403, 'Este apartado ya no recibe pagos.');

        $organization = $this->context->organization();

        abort_unless($organization->status->acceptsCustomerReceipts(), 403, PaymentNotApplicableException::CLOSED_MESSAGE);
        $summary = (new LayawaySummaryResource($layaway))->resolve($request);
        [$min, $max] = StoreReceiptRequest::boundsFor($layaway, new DateTimeZone($organization->timezone));

        return Inertia::render('portal/ReceiptCreate', [
            'business' => OrganizationBrand::of($organization, $this->limits->hasFeature(PlanFeature::Branding))->toArray(),
            'layaway' => [
                'code' => $summary['code'],
                'productName' => $summary['productName'],
                'statusLabel' => $summary['statusLabel'],
                'remaining' => $summary['remaining'],
            ],
            'suggestion' => $this->suggestion($layaway),
            'paymentAccounts' => array_map(fn (PaymentAccountView $account): array => $account->toArray(), $this->paymentAccounts->all()),
            'methods' => array_map(fn (PaymentMethod $method): array => [
                'value' => $method->value,
                'label' => $method->label(),
                'isCash' => $method->isCash(),
                'requiresReference' => $method->requiresReference(),
                'defaultCurrency' => $method->defaultCurrency()->value,
            ], PaymentMethod::cases()),
            'currencies' => array_map(fn (Currency $currency): array => ['value' => $currency->value, 'label' => $currency->label()], Currency::cases()),
            'paidAt' => ['min' => $min, 'max' => $max],
            'file' => ['maxMb' => ReceiptFile::maxSizeMb(), 'accept' => ReceiptFile::ACCEPT],
            'storeUrl' => route('portal.receipts.store', ['code' => $layaway->code]),
            'backUrl' => route('portal.layaways.show', ['code' => $layaway->code]),
            'replaces' => $this->rejectedToReplace($layaway, $organization->timezone),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(StoreReceiptRequest $request, string $code, PortalContext $portal, SubmitReceiptAction $action): RedirectResponse
    {
        $layaway = $portal->findLayaway($code);

        try {
            $action->handle($request->toData($layaway));
        } catch (DuplicateReceiptException $exception) {
            throw ValidationException::withMessages([$exception->field() => $exception->getMessage()]);
        } catch (CrossTenantRelationException) {
            throw ValidationException::withMessages(['paymentAccount' => 'Esa cuenta de cobro no está disponible. Elige otra de la lista.']);
        } catch (PaymentNotApplicableException $exception) {
            abort(403, $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return to_route('portal.layaways.show', ['code' => $layaway->code])->with('status', self::SUBMITTED_STATUS);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function suggestion(Layaway $layaway): ?array
    {
        $next = NextPayment::for($layaway, $layaway->installments()->get()->all());

        if ($next === null) {
            return null;
        }

        $snapshot = $this->rate->snapshot();

        return [
            'kind' => $next->kind->value,
            'installmentNumber' => $next->installmentNumber,
            'amount' => $this->money($next->amount),
            'started' => $next->started,
            'amountUsd' => self::decimal($next->amount),
            'amountVes' => $snapshot === null ? null : self::decimal($next->amount->convertTo(Currency::VES, $snapshot)),
        ];
    }

    private static function decimal(Money $amount): string
    {
        return sprintf('%d.%02d', intdiv($amount->cents, 100), $amount->cents % 100);
    }

    /**
     * @return array{submittedAt: string, reason: string|null}|null
     */
    private function rejectedToReplace(Layaway $layaway, string $timezone): ?array
    {
        $previous = $this->layawayReceipts->rejectedToReplace($layaway);

        if (! $previous instanceof Receipt) {
            return null;
        }

        return [
            'submittedAt' => ($previous->created_at ?? now())->setTimezone($timezone)->toDateString(),
            'reason' => $previous->rejection_reason ?? $previous->rejection_reason_code?->label(),
        ];
    }
}
