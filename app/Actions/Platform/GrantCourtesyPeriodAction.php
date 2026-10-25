<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\PlatformUser;
use App\Models\Subscription;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\Auditing\PlatformAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class GrantCourtesyPeriodAction
{
    use LogsSensitiveActivity;

    public const MAX_DAYS = 180;

    public const REASON_MIN = 10;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @throws InvalidArgumentException
     */
    public function handle(Organization $organization, PlatformUser $operator, CarbonImmutable $until, string $reason): Subscription
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException(sprintf('El motivo de la cortesía va de %d a 500 caracteres.', self::REASON_MIN));
        }

        $now = CarbonImmutable::now();

        if ($until->greaterThan($now->addDays(self::MAX_DAYS))) {
            throw new InvalidArgumentException(sprintf('Una cortesía llega como mucho a %d días desde hoy.', self::MAX_DAYS));
        }

        $subscription = $this->context->runFor($organization, fn (): Subscription => DB::transaction(function () use ($operator, $until, $reason): Subscription {
            /** @var Subscription $locked */
            $locked = Subscription::query()->lockForUpdate()->firstOrFail();

            if ($locked->status === SubscriptionStatus::Cancelled) {
                throw new InvalidArgumentException('Una suscripción cancelada no admite cortesía.');
            }

            $end = $locked->current_period_end?->toImmutable();

            if ($end !== null && ! $until->greaterThan($end)) {
                throw new InvalidArgumentException('La cortesía tiene que extender el período: la fecha va después del fin actual.');
            }

            $before = ['current_period_end' => $end?->toIso8601String()];
            $locked->forceFill(['current_period_end' => $until])->save();

            $this->audit(AuditEvent::PlatformSubscriptionOverridden, $locked, $operator, $before, [
                'current_period_end' => $until->toIso8601String(),
                'courtesy_reason' => $reason,
            ]);

            return $locked;
        }));

        PlatformAudit::record(PlatformAudit::COURTESY_GRANTED, 'Concedió cortesía hasta el '.$until->toDateString().': '.$reason, $operator, (int) $organization->getKey(), [
            'until' => $until->toIso8601String(),
        ]);

        return $subscription;
    }
}
