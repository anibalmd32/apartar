<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Account\PortalContext;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
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

final class ReceiptFileController extends Controller
{
    public function __invoke(Request $request, Receipt $receipt): StreamedResponse
    {
        Gate::forUser($this->actor($request))->authorize('view', $receipt);

        $disk = Storage::disk(Receipt::DISK);

        if (! $disk->exists($receipt->file_path)) {
            Log::error('receipt.file_missing', [
                'organization_id' => $receipt->organization_id,
                'receipt_id' => $receipt->getKey(),
                'file_path' => $receipt->file_path,
            ]);

            abort(404);
        }

        return $disk->response($receipt->file_path, basename($receipt->file_path), [
            'Content-Type' => $receipt->file_mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    private function actor(Request $request): User|Customer|null
    {
        if ($request->routeIs(SignedFileUrl::CUSTOMER_ROUTE)) {
            return app(PortalContext::class)->customerIn((int) app(TenantContext::class)->organization()->getKey());
        }

        if (! $request->routeIs(SignedFileUrl::BACKOFFICE_ROUTE)) {
            throw new LogicException('ReceiptFileController solo atiende las rutas de SignedFileUrl.');
        }

        $actor = Auth::guard('web')->user();

        return $actor instanceof User ? $actor : null;
    }
}
