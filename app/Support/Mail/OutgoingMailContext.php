<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Domain\Tenancy\TenantContext;

final class OutgoingMailContext
{
    /** @var array<string, int> */
    private array $organizations = [];

    public function __construct(private readonly TenantContext $tenant) {}

    public function remember(string $notificationId, int $organizationId): void
    {
        $this->organizations[$notificationId] = $organizationId;
    }

    /**
     * @param  array<mixed>  $data
     */
    public function organizationFor(array $data): ?int
    {
        $notificationId = $data['__laravel_notification_id'] ?? null;

        if (is_string($notificationId) && isset($this->organizations[$notificationId])) {
            return $this->organizations[$notificationId];
        }

        return $this->tenant->hasOrganization() ? $this->tenant->organizationId() : null;
    }
}
