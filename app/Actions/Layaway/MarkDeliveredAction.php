<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\LayawayNotDeliverableException;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Notification\CustomerNotices;
use App\Models\Layaway;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\ModelState;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class MarkDeliveredAction
{
    use LogsSensitiveActivity;

    /**
     * @throws AuthorizationException
     * @throws LayawayNotDeliverableException
     */
    public function handle(Layaway $layaway, User $actor): Layaway
    {
        Gate::forUser($actor)->authorize('manage', $layaway);

        return DB::transaction(function () use ($layaway, $actor): Layaway {
            /** @var Layaway $locked */
            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== LayawayStatus::Completed) {
                throw new LayawayNotDeliverableException($locked->status);
            }

            if ($locked->delivered_at === null) {
                $locked->forceFill(['delivered_at' => CarbonImmutable::now()])->save();

                $this->audit(
                    AuditEvent::LayawayDelivered,
                    subject: $locked,
                    actor: $actor,
                    after: ['delivered_at' => $locked->delivered_at?->toIso8601String()],
                );

                DB::afterCommit(fn () => app(CustomerNotices::class)->delivered($locked));
            }

            ModelState::adopt($layaway, $locked->getAttributes());

            return $layaway;
        });
    }
}
