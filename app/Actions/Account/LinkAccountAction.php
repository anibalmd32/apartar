<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Domain\Account\CustomerAlreadyLinkedException;
use App\Domain\Account\LinkOrigin;
use App\Domain\Auditing\AuditEvent;
use App\Models\Account;
use App\Models\Customer;
use App\Support\Auditing\LogsSensitiveActivity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class LinkAccountAction
{
    use LogsSensitiveActivity;

    /**
     * @throws CustomerAlreadyLinkedException
     */
    public function handle(Customer $customer, Account $account, LinkOrigin $origin): bool
    {
        try {
            return DB::transaction(function () use ($customer, $account, $origin): bool {
                $current = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

                if ($current->account_id !== null) {
                    return (int) $current->account_id === (int) $account->getKey()
                        ? false
                        : throw CustomerAlreadyLinkedException::toAnotherAccount();
                }

                if (Customer::query()->where('account_id', $account->getKey())->exists()) {
                    throw CustomerAlreadyLinkedException::accountHasAnotherRecord();
                }

                $current->forceFill(['account_id' => $account->getKey()])->save();
                $customer->setAttribute('account_id', $account->getKey());

                DB::table('push_subscriptions')
                    ->where('subscribable_type', $current->getMorphClass())
                    ->where('subscribable_id', $current->getKey())
                    ->update(['subscribable_type' => $account->getMorphClass(), 'subscribable_id' => $account->getKey()]);

                $this->audit(
                    AuditEvent::AccountLinked,
                    subject: $current,
                    before: ['linked' => false],
                    after: ['linked' => true, 'origin' => $origin->value],
                    process: self::processOf($origin),
                );

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            throw CustomerAlreadyLinkedException::accountHasAnotherRecord();
        }
    }

    private static function processOf(LinkOrigin $origin): string
    {
        return match ($origin) {
            LinkOrigin::LayawayCreation => 'apartado con cuenta de Google',
            LinkOrigin::VerifiedClaim => 'reclamo con cédula y código',
        };
    }
}
