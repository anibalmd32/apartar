<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Domain\Auditing\AuditEvent;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\PaymentSafeguard;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class RemoveSafeguardAction
{
    use LogsSensitiveActivity;

    /**
     * @throws AuthorizationException
     */
    public function handle(Payment $payment, User $actor): void
    {
        Gate::forUser($actor)->authorize('manage', Layaway::query()->findOrFail($payment->layaway_id));

        $proof = DB::transaction(function () use ($payment, $actor): ?string {
            $safeguard = PaymentSafeguard::query()->where('payment_id', $payment->getKey())->lockForUpdate()->first();

            if (! $safeguard instanceof PaymentSafeguard) {
                return null;
            }

            $before = RecordSafeguardAction::audited($safeguard);
            $safeguard->delete();
            $this->audit(AuditEvent::SafeguardRemoved, subject: $payment, actor: $actor, before: $before);

            return $safeguard->proof_path;
        });

        if ($proof === null) {
            return;
        }

        try {
            Storage::disk(Receipt::DISK)->delete($proof);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
