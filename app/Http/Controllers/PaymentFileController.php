<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Account\PortalContext;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\User;
use App\Support\SignedFileUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PaymentFileController extends Controller
{
    public function __invoke(Request $request, Payment $payment): StreamedResponse
    {
        Gate::forUser($this->actor($request))->authorize('viewAttachment', $payment);

        $disk = Storage::disk(Receipt::DISK);
        $path = (string) $payment->file_path;

        if (! $disk->exists($path)) {
            Log::error('payment.file_missing', [
                'organization_id' => $payment->organization_id,
                'payment_id' => $payment->getKey(),
                'file_path' => $path,
            ]);

            abort(404);
        }

        return $disk->response($path, basename($path), [
            'Content-Type' => (string) $payment->file_mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    private function actor(Request $request): User|Customer|null
    {
        if ($request->routeIs(SignedFileUrl::CUSTOMER_PAYMENT_ROUTE)) {
            return app(PortalContext::class)->customerIn((int) app(TenantContext::class)->organization()->getKey());
        }

        if (! $request->routeIs(SignedFileUrl::BACKOFFICE_PAYMENT_ROUTE)) {
            throw new LogicException('PaymentFileController solo atiende las rutas de pagos de SignedFileUrl.');
        }

        $actor = Auth::guard('web')->user();

        return $actor instanceof User ? $actor : null;
    }
}
