<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Customer\CustomerAlreadyExistsException;
use App\Domain\Customer\TermsConsent;
use App\Models\Customer;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CreateCustomerAction
{
    use LogsSensitiveActivity;

    public const PROCESS = 'alta de cliente desde el backoffice';

    public function __construct(private readonly ResolveCustomerAction $customers) {}

    /**
     * @throws CustomerAlreadyExistsException
     */
    public function handle(CustomerContactData $contact, TermsConsent $consent, User $actor): Customer
    {
        Gate::forUser($actor)->authorize('create', Customer::class);

        return DB::transaction(function () use ($contact, $consent, $actor): Customer {
            $existing = Customer::query()->where('document_number', $contact->documentNumber->value)->first();

            if ($existing instanceof Customer) {
                throw new CustomerAlreadyExistsException($existing);
            }

            $customer = $this->customers->declaredBy($contact, $consent, $actor);

            if (! $customer->wasRecentlyCreated) {
                throw new CustomerAlreadyExistsException($customer);
            }

            $this->audit(
                AuditEvent::CustomerCreated,
                subject: $customer,
                actor: $actor,
                after: [
                    'full_name' => $customer->full_name,
                    'document_number' => $customer->document_number,
                    'phone' => $customer->phone,
                    'email' => $customer->email,
                    'terms_declared_by_user_id' => $customer->terms_declared_by_user_id,
                ],
                process: self::PROCESS,
            );

            return $customer;
        });
    }
}
