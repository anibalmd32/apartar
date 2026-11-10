<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Payment\RecordSafeguardAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\RecordSafeguardRequest;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\PaymentSafeguard;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PaymentSafeguardController extends Controller
{
    public const SAVED = 'Resguardo guardado.';

    public const REMOVED = 'Resguardo borrado.';

    public function store(RecordSafeguardRequest $request, Payment $payment, RecordSafeguardAction $record): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $record->handle($payment, $request->toData(), $actor);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['destination' => $exception->getMessage()]);
        }

        return back()->with('status', self::SAVED);
    }

    public function destroy(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorizeFor($request, $payment);

        $safeguard = PaymentSafeguard::query()->where('payment_id', $payment->getKey())->first();

        if ($safeguard instanceof PaymentSafeguard) {
            $path = $safeguard->proof_path;
            $safeguard->delete();

            if ($path !== null) {
                Storage::disk(Receipt::DISK)->delete($path);
            }
        }

        return back()->with('status', self::REMOVED);
    }

    public function proof(Request $request, Payment $payment): StreamedResponse
    {
        $this->authorizeFor($request, $payment);

        $safeguard = PaymentSafeguard::query()->where('payment_id', $payment->getKey())->first();
        $path = $safeguard?->proof_path;
        $disk = Storage::disk(Receipt::DISK);

        abort_if($path === null || ! $disk->exists($path), 404);

        return $disk->response($path, basename($path), [
            'Content-Type' => (string) $safeguard->proof_mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    private function authorizeFor(Request $request, Payment $payment): void
    {
        Gate::forUser($request->user())->authorize('manage', Layaway::query()->findOrFail($payment->layaway_id));
    }
}
