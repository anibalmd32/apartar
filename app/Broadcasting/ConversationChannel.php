<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Domain\Tenancy\OrganizationCapability;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;

final class ConversationChannel
{
    public function __construct(private readonly TenantContext $context) {}

    public function join(User|Customer|Account|null $user, int|string $orgId, int|string $conversationId): bool
    {
        if ($user === null || ! ctype_digit((string) $orgId) || ! ctype_digit((string) $conversationId)) {
            return false;
        }

        $organization = Organization::query()->find((int) $orgId);

        if (! $organization instanceof Organization || (string) $organization->getKey() !== (string) $orgId) {
            return false;
        }

        $ownerId = $this->context->runFor($organization, fn (): mixed => Conversation::query()->whereKey((int) $conversationId)->value('customer_id'));

        if ($ownerId === null) {
            return false;
        }

        return match (true) {
            $user instanceof User => $this->isActiveMember($user, $organization),
            $user instanceof Customer => (string) $user->organization_id === (string) $orgId && (int) $user->getKey() === (int) $ownerId,
            $user instanceof Account => $this->context->runFor($organization, fn (): bool => Customer::query()
                ->whereKey((int) $ownerId)
                ->where('account_id', $user->getKey())
                ->exists()),
        };
    }

    private function isActiveMember(User $user, Organization $organization): bool
    {
        return $user->disabled_at === null
            && (int) $user->organization_id === (int) $organization->getKey()
            && $organization->status !== OrganizationStatus::Closed
            && $user->role->can(OrganizationCapability::Chat);
    }
}
