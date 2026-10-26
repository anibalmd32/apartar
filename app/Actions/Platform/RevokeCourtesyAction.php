<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\PlatformUser;
use App\Models\Subscription;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\Auditing\PlatformAudit;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RevokeCourtesyAction
{
    use LogsSensitiveActivity;

    public const REASON_MIN = 10;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @throws InvalidArgumentException
     */
    public function handle(Organization $organization, PlatformUser $operator, string $reason): Subscription
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException(sprintf('El motivo va de %d a 500 caracteres.', self::REASON_MIN));
        }

        $subscription = $this->context->runFor($organization, fn (): Subscription => DB::transaction(function () use ($operator, $reason): Subscription {
            /** @var Subscription $locked */
            $locked = Subscription::query()->lockForUpdate()->firstOrFail();

            if (! $locked->hasRevocableCourtesy()) {
                throw new InvalidArgumentException('No hay una cortesía vigente que revocar: venció o el período cambió después.');
            }

            $before = ['current_period_end' => $locked->current_period_end?->toIso8601String()];
            $restored = $locked->courtesy_previous_period_end;
            $locked->forceFill(['current_period_end' => $restored, 'courtesy_until' => null, 'courtesy_previous_period_end' => null])->save();

            $this->audit(AuditEvent::PlatformSubscriptionOverridden, $locked, $operator, $before, [
                'current_period_end' => $restored?->toIso8601String(),
                'courtesy_revoked_reason' => $reason,
            ]);

            return $locked;
        }));

        $restored = $subscription->current_period_end?->setTimezone($organization->timezone)->toDateString();
        PlatformAudit::record(
            PlatformAudit::COURTESY_REVOKED,
            sprintf('Revocó la cortesía; el período vuelve a %s: %s', $restored === null ? 'no tener fin' : 'terminar el '.$restored, $reason),
            $operator,
            (int) $organization->getKey(),
        );

        return $subscription;
    }
}
