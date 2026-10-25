<?php

declare(strict_types=1);

use App\Broadcasting\ConversationChannel;
use App\Domain\Tenancy\OrganizationCapability;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(
    'organization.{orgId}.receipts',
    function (User|Customer|null $user, int|string $orgId): bool {
        if (! $user instanceof User || $user->disabled_at !== null) {
            return false;
        }

        if ((string) $user->organization_id !== (string) $orgId) {
            return false;
        }

        if ($user->organization?->status === OrganizationStatus::Closed) {
            return false;
        }

        return $user->role->can(OrganizationCapability::Receipts);
    },
    ['guards' => ['web']],
);

Broadcast::channel(
    'organization.{orgId}.conversation.{conversationId}',
    ConversationChannel::class,
    ['guards' => ['web', 'customer', 'account']],
);

Broadcast::channel(
    'organization.{orgId}.chat',
    function (User|Customer|null $user, int|string $orgId): bool {
        if (! $user instanceof User || $user->disabled_at !== null) {
            return false;
        }

        if ((string) $user->organization_id !== (string) $orgId) {
            return false;
        }

        if ($user->organization?->status === OrganizationStatus::Closed) {
            return false;
        }

        return $user->role->can(OrganizationCapability::Chat);
    },
    ['guards' => ['web']],
);
