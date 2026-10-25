<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\VoidInvoiceAction;
use App\Actions\Subscription\ApproveSubscriptionReceiptAction;
use App\Actions\Subscription\ApproveSubscriptionReceiptData;
use App\Actions\Subscription\RejectSubscriptionReceiptAction;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Money\Currency;
use App\Domain\Payment\ReceiptAlreadyProcessedException;
use App\Domain\Subscription\InvoiceAlreadyPaidException;
use App\Domain\Subscription\InvoiceNotPayableException;
use App\Http\Controllers\Controller;
use App\Models\PlatformUser;
use App\Models\Receipt;
use App\Queries\Platform\SubscriptionReceiptInbox;
use App\Support\Auditing\PlatformAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InvoiceController extends Controller
{
    public function index(SubscriptionReceiptInbox $inbox): Response
    {
        return Inertia::render('platform/invoices/Index', ['receipts' => $inbox->pending()]);
    }

    public function approve(Request $request, int $receipt, SubscriptionReceiptInbox $inbox, ApproveSubscriptionReceiptAction $approve): RedirectResponse
    {
        $data = $request->validate([
            'settledAmount' => ['required', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'settledCurrency' => ['required', Rule::enum(Currency::class)],
            'rate' => ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,8})?$/'],
        ]);

        $found = $inbox->find($receipt) ?? abort(404);
        $operator = $this->operator($request);

        try {
            $invoice = $approve->handle($found, new ApproveSubscriptionReceiptData($data['settledAmount'], Currency::from($data['settledCurrency']), $data['rate'] ?? null), $operator);
        } catch (ReceiptAlreadyProcessedException|InvoiceNotPayableException $exception) {
            throw ValidationException::withMessages(['receipt' => $exception->getMessage()]);
        } catch (MissingExchangeRateException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['settledAmount' => $exception->getMessage()]);
        }

        PlatformAudit::record(PlatformAudit::INVOICE_APPROVED, 'Aprobó un comprobante de la factura #'.$invoice->getKey().'.', $operator, (int) $found->organization_id, [
            'receipt_id' => $found->getKey(),
            'invoice_status' => $invoice->status->value,
        ]);

        return back()->with('status', 'Comprobante aprobado.');
    }

    public function reject(Request $request, int $receipt, SubscriptionReceiptInbox $inbox, RejectSubscriptionReceiptAction $reject): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:'.RejectSubscriptionReceiptAction::REASON_MAX]]);
        $found = $inbox->find($receipt) ?? abort(404);
        $operator = $this->operator($request);

        try {
            $reject->handle($found, $data['reason'], $operator);
        } catch (ReceiptAlreadyProcessedException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        PlatformAudit::record(PlatformAudit::INVOICE_REJECTED, 'Rechazó un comprobante: '.$data['reason'], $operator, (int) $found->organization_id, ['receipt_id' => $found->getKey()]);

        return back()->with('status', 'Comprobante rechazado.');
    }

    public function void(Request $request, int $invoice, SubscriptionReceiptInbox $inbox, VoidInvoiceAction $void): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:'.VoidInvoiceAction::REASON_MIN, 'max:'.VoidInvoiceAction::REASON_MAX]]);
        $found = $inbox->findInvoice($invoice) ?? abort(404);

        try {
            $void->handle($found, $this->operator($request), $data['reason']);
        } catch (InvoiceAlreadyPaidException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return back()->with('status', 'Factura anulada.');
    }

    public function file(int $receipt, SubscriptionReceiptInbox $inbox): StreamedResponse
    {
        $found = $inbox->find($receipt) ?? abort(404);
        $disk = Storage::disk(Receipt::DISK);

        abort_unless($disk->exists($found->file_path), 404);

        return $disk->response($found->file_path, null, ['Content-Type' => $found->file_mime, 'Cache-Control' => 'private, no-store']);
    }

    private function operator(Request $request): PlatformUser
    {
        $user = $request->user('platform');

        return $user instanceof PlatformUser ? $user : abort(401);
    }
}
