<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class LastOwnerGuard
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws LastOwnerException
     * @throws CrossTenantRelationException
     */
    public function whileKeepingAnOwner(User $subject, string $operation, Closure $callback): mixed
    {
        $this->assertBelongsToActiveOrganization($subject);

        return DB::transaction(function () use ($subject, $operation, $callback): mixed {
            $remaining = $this->activeOwners()->reject(fn (User $owner): bool => $owner->is($subject));

            if ($remaining->isEmpty()) {
                throw LastOwnerException::wouldLeaveNone($subject->name, $operation);
            }

            return $callback();
        });
    }

    public function isLastActiveOwner(User $subject): bool
    {
        $this->assertBelongsToActiveOrganization($subject);

        $owners = $this->activeOwners(locked: false);

        return $owners->isNotEmpty()
            && $owners->reject(fn (User $owner): bool => $owner->is($subject))->isEmpty();
    }

    /**
     * @return Collection<int, User>
     */
    private function activeOwners(bool $locked = true): Collection
    {
        $query = User::query()
            ->where('role', OrganizationRole::Owner)
            ->whereNull('disabled_at');

        if ($locked) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    private function assertBelongsToActiveOrganization(User $subject): void
    {
        if ($subject->organization_id !== $this->context->organizationId()) {
            throw CrossTenantRelationException::between(User::class, Organization::class);
        }
    }
}
