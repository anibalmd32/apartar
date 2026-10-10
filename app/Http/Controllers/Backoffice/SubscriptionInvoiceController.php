<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Subscription\SubmitSubscriptionReceiptAction;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\InvoiceNotPayableException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\SubmitSubscriptionReceiptRequest;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionReceipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * El historial de facturas de la suscripción y el pago de cada una
 * (`REQ-SUB-006` RB-1, RB-7, RB-10, `T-069`): `GET
 * /backoffice/suscripcion/facturas` y `POST
 * /backoffice/suscripcion/facturas/{invoice}/comprobante`.
 *
 * Las dos sobreviven a la suspensión: pagar es justo lo que la levanta
 * (`REQ-SUB-007` RB-5). Las gobierna la capacidad `billing`
 * (`SubscriptionPolicy::viewInvoices`, `uploadPaymentProof`); una factura de
 * otra organización no existe aquí (`404` por el global scope).
 *
 * Cada fila es un `InvoiceRow` ([50] contratos compartidos), con el importe en
 * dólares y su equivalente en bolívares **a la tasa de la plataforma**, que es
 * con la que se cobra (RB-4) —no la del negocio—.
 */
final class SubscriptionInvoiceController extends Controller
{
    public function index(TenantContext $context, ExchangeRateService $rates): Response
    {
        Gate::authorize('viewInvoices', Subscription::query()->firstOrFail());

        $organization = $context->organization();
        $today = CarbonImmutable::now($organization->timezone);
        $rate = $rates->current(null);

        $invoices = SubscriptionInvoice::query()
            ->with(['plan', 'receipts' => fn ($query) => $query->latest('id')])
            ->latest('issued_at')
            ->latest('id')
            ->get();

        return Inertia::render('backoffice/subscription/Invoices', [
            'invoices' => $invoices->map(fn (SubscriptionInvoice $invoice): array => $this->row($invoice, $today, $rate))->values()->all(),
            'methods' => array_map(fn (PaymentMethod $method): array => [
                'value' => $method->value,
                'label' => $method->label(),
                'requiresReference' => $method->requiresReference(),
                'defaultCurrency' => $method->defaultCurrency()->value,
            ], PaymentMethod::cases()),
            'currencies' => array_map(fn (Currency $currency): array => ['value' => $currency->value, 'label' => $currency->label()], Currency::cases()),
            'file' => ['maxMb' => ReceiptFile::MAX_SIZE_MB, 'accept' => ReceiptFile::ACCEPT],
            'subscriptionUrl' => route('subscription.show'),
        ]);
    }

    /**
     * @throws ValidationException con el error en su campo
     */
    public function storeReceipt(SubmitSubscriptionReceiptRequest $request, SubscriptionInvoice $invoice, SubmitSubscriptionReceiptAction $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $action->handle($request->toData($invoice), $actor);
        } catch (DuplicateReceiptException $exception) {
            throw ValidationException::withMessages([$exception->field() => $exception->getMessage()]);
        } catch (InvoiceNotPayableException $exception) {
            throw ValidationException::withMessages(['invoice' => $exception->getMessage()]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return to_route('subscription.invoices.index')->with('status', 'Recibimos tu comprobante. Lo revisamos y aquí verás el resultado.');
    }

    /**
     * Una factura como `InvoiceRow`, con lo que la pantalla necesita para
     * pagarla sin decidir nada.
     *
     * @return array<string, mixed>
     */
    private function row(SubscriptionInvoice $invoice, CarbonImmutable $today, ?RateSnapshot $rate): array
    {
        $status = $invoice->displayStatus($today);
        $open = $status->acceptsReceipts();
        // De una factura ya cerrada no hay comprobante que comentar: el que se
        // rechazó solo por «ya pagada» invitaría a enviar otro que no se
        // admite (revisión de `T-069`).
        $lastReceipt = $open ? $invoice->receipts->first() : null;

        return [
            'id' => $invoice->getKey(),
            'planName' => $invoice->plan->name,
            'kind' => $invoice->kind->label(),
            'periodStart' => $invoice->period_start->toIso8601String(),
            'periodEnd' => $invoice->period_end->toIso8601String(),
            // RB-4 · el bolívar de una factura cerrada no se recalcula a la
            // tasa de hoy: lo que se pagó está congelado en su comprobante.
            'amount' => $this->money($invoice->amount(), $open ? $rate : null),
            'outstanding' => $invoice->paid_cents > 0 && $invoice->outstanding()->cents > 0 ? $this->money($invoice->outstanding(), $rate) : null,
            'status' => $status->value,
            'dueDate' => $invoice->due_date->toDateString(),
            'receiptStatus' => match (true) {
                ! $lastReceipt instanceof SubscriptionReceipt, $lastReceipt->status === ReceiptStatus::Approved => 'none',
                $lastReceipt->status === ReceiptStatus::PendingReview => 'pending_review',
                default => 'rejected',
            },
            'rejectionReason' => $lastReceipt?->status === ReceiptStatus::Rejected ? $lastReceipt->rejection_reason : null,
            'payUrl' => $open ? route('subscription.invoices.receipt', $invoice) : null,
        ];
    }

    /**
     * @return array{cents: int, usd: string, ves: string|null}
     */
    private function money(Money $amount, ?RateSnapshot $rate): array
    {
        return [
            'cents' => $amount->cents,
            'usd' => MoneyFormatter::format($amount),
            // Sin tasa, el bolívar no aparece (`REQ-FX-005` RB-4).
            'ves' => $rate === null ? null : MoneyFormatter::format($amount->convertTo(Currency::VES, $rate)),
        ];
    }
}
