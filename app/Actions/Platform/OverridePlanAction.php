<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Actions\Subscription\ApplyDowngradeAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlatformUser;
use App\Models\Subscription;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\Auditing\PlatformAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class OverridePlanAction
{
    use LogsSensitiveActivity;

    public const REASON_MIN = 10;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ApplyDowngradeAction $downgrade,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public function handle(Organization $organization, Plan $target, PlatformUser $operator, string $reason): Subscription
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException(sprintf('El motivo del cambio de plan va de %d a 500 caracteres.', self::REASON_MIN));
        }

        $subscription = $this->context->runFor($organization, function () use ($target, $operator, $reason): Subscription {
            $result = DB::transaction(function () use ($target, $operator, $reason): array {
                /** @var Subscription $locked */
                $locked = Subscription::query()->with('plan')->lockForUpdate()->firstOrFail();
                $from = $locked->plan;
                $downgrades = $from->ranksAbove($target);

                $locked->forceFill($downgrades
                    ? ['scheduled_plan_id' => $target->getKey(), 'scheduled_plan_at' => CarbonImmutable::now()]
                    : ['plan_id' => $target->getKey(), 'scheduled_plan_id' => null, 'scheduled_plan_at' => null])->save();

                $this->audit(AuditEvent::PlatformSubscriptionOverridden, $locked, $operator, ['plan' => $from->code], [
                    'plan' => $target->code,
                    'reason' => $reason,
                    'via' => $downgrades ? 'downgrade' : 'immediate',
                ]);

                return [$locked, $downgrades];
            });

            [$locked, $downgrades] = $result;

            if ($downgrades) {
                $this->downgrade->handle($locked);
            }

            return $locked->refresh();
        });

        PlatformAudit::record(PlatformAudit::PLAN_OVERRIDDEN, 'Cambió el plan a '.$target->name.': '.$reason, $operator, (int) $organization->getKey(), [
            'plan' => $target->code,
        ]);

        return $subscription;
    }
}
