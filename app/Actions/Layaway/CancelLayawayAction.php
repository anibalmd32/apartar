<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\IllegalLayawayTransitionException;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\StaffReason;
use App\Domain\Payment\LayawayReceipts;
use App\Models\Layaway;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\ModelState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class CancelLayawayAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly ReleaseStockAction $stock,
        private readonly LayawayStateMachine $machine,
        private readonly LayawayReceipts $receipts,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws IllegalLayawayTransitionException
     */
    public function handle(Layaway $layaway, User $actor, string $reason): Layaway
    {
        Gate::forUser($actor)->authorize('manage', $layaway);

        $reason = new StaffReason($reason);

        return DB::transaction(function () use ($layaway, $actor, $reason): Layaway {
            $this->stock->lockProductOf($layaway);

            /** @var Layaway $locked */
            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo(LayawayStatus::Cancelled)) {
                throw new IllegalLayawayTransitionException($from, LayawayStatus::Cancelled, $locked->getKey());
            }

            $rejected = $this->receipts->rejectPendingOnCancellation($locked);

            $this->machine->transition($locked, LayawayStatus::Cancelled);
            $locked->forceFill(['cancellation_reason' => $reason->text])->save();

            $this->audit(
                AuditEvent::LayawayCancelled,
                subject: $locked,
                actor: $actor,
                before: ['status' => $from->value],
                after: [
                    'status' => LayawayStatus::Cancelled->value,
                    'cancellation_reason' => $reason->text,
                ] + ($rejected > 0 ? ['receipts_rejected' => $rejected] : []),
            );

            ModelState::adopt($layaway, $locked->getAttributes());

            return $layaway;
        });
    }
}
