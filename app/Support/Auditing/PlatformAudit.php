<?php

declare(strict_types=1);

namespace App\Support\Auditing;

use App\Models\PlatformActivity;
use App\Models\PlatformUser;
use Throwable;

final class PlatformAudit
{
    public const SUPPORT_GRANTED = 'support_access.granted';

    public const SUPPORT_REVOKED = 'support_access.revoked';

    public const SUPPORT_VIEWED = 'support_access.viewed';

    public const INVOICE_APPROVED = 'invoice.receipt_approved';

    public const INVOICE_REJECTED = 'invoice.receipt_rejected';

    public const INVOICE_VOIDED = 'invoice.voided';

    public const PLAN_OVERRIDDEN = 'subscription.plan_overridden';

    public const COURTESY_GRANTED = 'subscription.courtesy_granted';

    public const ORGANIZATION_SUSPENDED = 'organization.suspended';

    public const ORGANIZATION_REACTIVATED = 'organization.reactivated';

    public const ORGANIZATION_CLOSED = 'organization.closed';

    public const LOGIN = 'operator.login';

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(string $action, string $description, ?PlatformUser $actor = null, ?int $organizationId = null, array $properties = []): void
    {
        try {
            $entry = new PlatformActivity;
            $entry->forceFill([
                'platform_user_id' => $actor?->getKey(),
                'target_organization_id' => $organizationId,
                'action' => $action,
                'description' => mb_substr($description, 0, 500),
                'properties' => $properties,
                'created_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
