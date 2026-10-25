<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Platform\PlatformSettingKey;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\PlatformUser;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Notifications\SupportAccessGrantedNotification;
use App\Queries\Platform\SupportGrants;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\Auditing\PlatformAudit;
use App\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Throwable;

final class GrantSupportAccessAction
{
    use LogsSensitiveActivity;

    public const REASON_MIN = 20;

    public const REASON_MAX = 500;

    public function __construct(
        private readonly TenantContext $context,
        private readonly SupportGrants $grants,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public function handle(PlatformUser $operator, Organization $organization, string $reason): SupportAccessGrant
    {
        $reason = trim($reason);
        $length = mb_strlen($reason);

        if ($length < self::REASON_MIN || $length > self::REASON_MAX) {
            throw new InvalidArgumentException(sprintf('El motivo tiene que tener entre %d y %d caracteres.', self::REASON_MIN, self::REASON_MAX));
        }

        $now = CarbonImmutable::now();
        $expiresAt = $now->addHours(PlatformSettings::get(PlatformSettingKey::SupportAccessTtlHours));

        $grant = $this->context->runFor($organization, fn (): SupportAccessGrant => DB::transaction(function () use ($operator, $organization, $reason, $now, $expiresAt): SupportAccessGrant {
            $current = $this->grants->activeFor((int) $operator->getKey(), (int) $organization->getKey(), $now);

            if ($current instanceof SupportAccessGrant) {
                $before = ['expires_at' => $current->expires_at->toIso8601String()];
                $current->forceFill(['expires_at' => $expiresAt])->save();
                $this->audit(AuditEvent::SupportAccessGranted, $current, $operator, $before, ['expires_at' => $expiresAt->toIso8601String(), 'reason' => $reason, 'extended' => true]);

                return $current;
            }

            $grant = new SupportAccessGrant;
            $grant->forceFill([
                'platform_user_id' => $operator->getKey(),
                'reason' => $reason,
                'granted_at' => $now,
                'expires_at' => $expiresAt,
            ])->save();

            $this->audit(AuditEvent::SupportAccessGranted, $grant, $operator, [], ['reason' => $reason, 'expires_at' => $expiresAt->toIso8601String()]);

            return $grant;
        }));

        PlatformAudit::record(PlatformAudit::SUPPORT_GRANTED, 'Abrió acceso de soporte: '.$reason, $operator, (int) $organization->getKey(), [
            'grant_id' => $grant->getKey(),
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        $this->notifyOwners($organization, $operator, $reason, $expiresAt);

        return $grant;
    }

    private function notifyOwners(Organization $organization, PlatformUser $operator, string $reason, CarbonImmutable $expiresAt): void
    {
        try {
            $owners = $this->context->runFor($organization, fn () => User::query()
                ->where('role', OrganizationRole::Owner)
                ->whereNull('disabled_at')
                ->get());

            $when = $expiresAt->setTimezone($organization->timezone)->locale('es')->isoFormat('D [de] MMMM, HH:mm');

            Notification::send($owners, new SupportAccessGrantedNotification($operator->name, $reason, $when));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
