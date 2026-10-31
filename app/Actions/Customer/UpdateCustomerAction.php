<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Domain\Auditing\AuditEvent;
use App\Models\Customer;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpdateCustomerAction
{
    use LogsSensitiveActivity;

    public const PROCESS = 'ficha del cliente en el backoffice';

    private const FIELDS = ['full_name', 'phone', 'email'];

    public function handle(Customer $customer, CustomerContactData $contact, User $actor): Customer
    {
        Gate::forUser($actor)->authorize('update', $customer);

        return DB::transaction(function () use ($customer, $contact, $actor): Customer {
            /** @var Customer $locked */
            $locked = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            $before = $locked->only(self::FIELDS);

            $locked->forceFill([
                'full_name' => $contact->fullName,
                'phone' => $contact->phone->value,
                'email' => $contact->email,
            ])->save();

            $after = $locked->only(self::FIELDS);

            if ($before !== $after) {
                $this->audit(AuditEvent::CustomerUpdated, subject: $locked, actor: $actor, before: $before, after: $after, process: self::PROCESS);
            }

            return $locked;
        });
    }
}
