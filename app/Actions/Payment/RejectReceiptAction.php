<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Actions\Layaway\ExtendDownPaymentWindowAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\DownPaymentExtension;
use App\Domain\Layaway\StaffReason;
use App\Domain\Notification\CustomerNotices;
use App\Domain\Payment\ReceiptAlreadyProcessedException;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Payment\RejectionReasonCode;
use App\Models\Layaway;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\ModelState;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class RejectReceiptAction
{
    use LogsSensitiveActivity;

    public function __construct(private readonly ExtendDownPaymentWindowAction $extension) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws ReceiptAlreadyProcessedException
     */
    public function handle(Receipt $receipt, RejectionReasonCode $code, string $reason, User $actor): DownPaymentExtension
    {
        Gate::forUser($actor)->authorize('review', $receipt);

        if ($code === RejectionReasonCode::LayawayCancelled) {
            throw new InvalidArgumentException('Ese motivo es el del rechazo automático al cancelar el apartado; elige otro.');
        }

        $text = new StaffReason($reason);

        return DB::transaction(function () use ($receipt, $code, $text, $actor): DownPaymentExtension {
            /** @var Layaway $layaway */
            $layaway = Layaway::query()->whereKey($receipt->layaway_id)->lockForUpdate()->firstOrFail();

            /** @var Receipt $locked */
            $locked = Receipt::query()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->awaitsReview()) {
                throw ReceiptAlreadyProcessedException::of($locked);
            }

            $now = CarbonImmutable::now();

            $locked->forceFill([
                'status' => ReceiptStatus::Rejected,
                'rejection_reason_code' => $code,
                'rejection_reason' => $text->text,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => $now,
            ])->save();

            $extension = $this->extension->handle($layaway, $now);

            $this->audit(
                AuditEvent::ReceiptRejected,
                subject: $locked,
                actor: $actor,
                before: ['status' => ReceiptStatus::PendingReview->value],
                after: [
                    'status' => ReceiptStatus::Rejected->value,
                    'rejection_reason_code' => $code->value,
                    'rejection_reason' => $text->text,
                ] + ($extension->wasGranted() ? ['down_payment_extended_until' => $layaway->expires_at?->toIso8601String()] : []),
            );

            ModelState::adopt($receipt, $locked->getAttributes());

            $deadline = $extension->wasGranted() ? $layaway->expires_at : null;
            DB::afterCommit(fn () => app(CustomerNotices::class)->receiptRejected($layaway, $text->text, $deadline));

            return $extension;
        });
    }
}
