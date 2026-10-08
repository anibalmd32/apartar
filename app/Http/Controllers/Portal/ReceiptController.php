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

/**
 * El cliente reporta un pago desde el portal (`REQ-PAY-001`).
 *
 * `GET` y `POST /mi-cuenta/apartados/{code}/comprobante`, detrás de la sesión
 * del portal y con el tenant de la ficha. El apartado se busca **entre las
 * fichas de la sesión** (`PortalContext::findLayaway()`): uno de otra persona
 * o de otro negocio es el mismo `404` que en el detalle (RB-1).
 *
 * ## Un apartado que ya no cobra no recibe comprobantes
 *
 * `403` en las dos (SP-4). En el `POST` lo dice `StoreReceiptRequest::authorize()`,
 * antes de validar; en el `GET`, aquí. La acción lo vuelve a comprobar bajo el
 * bloqueo, porque entre el formulario y el envío el negocio pudo cancelarlo.
 *
 * ## Qué recibe el formulario
 *
 * Todo resuelto, para que la página no calcule nada: las cuentas de cobro
 * **activas** del negocio (RB-3), los métodos con lo que cada uno exige y la
 * moneda que sugiere (RB-2, SP-10), los dos bordes de la fecha en el
 * calendario del negocio (RB-4) y el importe sugerido (RB-9) —el mismo
 * `NextPayment` del detalle— formateado y en crudo, en dólares y en
 * bolívares a la tasa de **esta** organización, como ayuda y sin imponerlo.
 *
 * ## Al enviar
 *
 * `SubmitReceiptAction` crea el comprobante en revisión sin tocar el saldo
 * (RB-6). Sus rechazos vuelven como errores de su campo: un duplicado en el
 * archivo o en la referencia (`REQ-PAY-003`), un apartado que dejó de cobrar
 * como `403`. Si va bien, vuelve al detalle con un aviso.
 *
 * Funciona con el negocio suspendido (RB-11): el portal no pregunta por la
 * suspensión. El límite de diez comprobantes por hora y por ficha (RB-12) lo
 * pone la ruta (`throttle:comprobantes`).
 */
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
            'file' => ['maxMb' => ReceiptFile::MAX_SIZE_MB, 'accept' => ReceiptFile::ACCEPT],
            'storeUrl' => route('portal.receipts.store', ['code' => $layaway->code]),
            'backUrl' => route('portal.layaways.show', ['code' => $layaway->code]),
            // `REQ-PAY-006` RB-4 · si lo último que reportó se rechazó, este
            // envío lo sustituye: se dice cuál y por qué, para corregir lo que
            // hay que corregir. El enlace lo hace `SubmitReceiptAction`.
            'replaces' => $this->rejectedToReplace($layaway, $organization->timezone),
        ]);
    }

    /**
     * @throws ValidationException con el error en su campo
     */
    public function store(StoreReceiptRequest $request, string $code, PortalContext $portal, SubmitReceiptAction $action): RedirectResponse
    {
        $layaway = $portal->findLayaway($code);

        try {
            $action->handle($request->toData($layaway));
        } catch (DuplicateReceiptException $exception) {
            throw ValidationException::withMessages([$exception->field() => $exception->getMessage()]);
        } catch (CrossTenantRelationException) {
            // SP-7 · ya lo para la regla; si llegara, el mismo mensaje y sin
            // decir que la cuenta existe en otro negocio.
            throw ValidationException::withMessages(['paymentAccount' => 'Esa cuenta de cobro no está disponible. Elige otra de la lista.']);
        } catch (PaymentNotApplicableException $exception) {
            // SP-4 · el negocio lo cerró entre el formulario y el envío.
            abort(403, $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return to_route('portal.layaways.show', ['code' => $layaway->code])->with('status', self::SUBMITTED_STATUS);
    }

    /**
     * RB-9 · el importe que se sugiere, formateado para enseñarlo y en crudo
     * para precargar el campo según la moneda que elija el cliente.
     *
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
            'amountUsd' => self::decimal($next->amount),
            'amountVes' => $snapshot === null ? null : self::decimal($next->amount->convertTo(Currency::VES, $snapshot)),
        ];
    }

    /**
     * Los centavos de un importe positivo como decimal sin formato, `1275.00`:
     * lo que el campo admite y `PaymentAmounts` acepta.
     */
    private static function decimal(Money $amount): string
    {
        return sprintf('%d.%02d', intdiv($amount->cents, 100), $amount->cents % 100);
    }

    /**
     * El comprobante rechazado al que sustituiría este envío, o `null`.
     *
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
