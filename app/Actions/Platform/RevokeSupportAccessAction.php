<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Models\PlatformUser;
use App\Models\SupportAccessGrant;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\Auditing\PlatformAudit;
use Carbon\CarbonImmutable;

final class RevokeSupportAccessAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantResolver $resolver,
    ) {}

    public function handle(SupportAccessGrant $grant, PlatformUser $operator): SupportAccessGrant
    {
        if (! $grant->isActive()) {
            return $grant;
        }

        $organization = $this->resolver->forOrganizationId((int) $grant->organization_id);

        $this->context->runFor($organization, function () use ($grant, $operator): void {
            $grant->forceFill(['revoked_at' => CarbonImmutable::now(), 'revoked_by' => $operator->getKey()])->save();
            $this->audit(AuditEvent::SupportAccessRevoked, $grant, $operator, ['revoked_at' => null], ['revoked_at' => $grant->revoked_at?->toIso8601String()]);
        });

        PlatformAudit::record(PlatformAudit::SUPPORT_REVOKED, 'Cerró el acceso de soporte.', $operator, (int) $grant->organization_id, ['grant_id' => $grant->getKey()]);

        return $grant;
    }
}
