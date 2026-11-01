<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Customer\CustomerAlreadyExistsException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Organization;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;

final class CompleteCustomerDocumentAction
{
    use LogsSensitiveActivity;

    public const PROCESS = 'el cliente escribió su cédula en el portal';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @throws CustomerAlreadyExistsException
     */
    public function handle(Customer $customer, DocumentNumber $document): Customer
    {
        $organization = Organization::query()->findOrFail($customer->organization_id);

        return $this->context->runFor($organization, fn (): Customer => DB::transaction(function () use ($customer, $document): Customer {
            /** @var Customer $locked */
            $locked = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->document_number !== null) {
                return $locked;
            }

            $holder = Customer::withTrashed()->where('document_number', $document->value)->lockForUpdate()->first();

            if ($holder instanceof Customer) {
                throw new CustomerAlreadyExistsException($holder);
            }

            $locked->forceFill(['document_number' => $document->value, 'document_kind' => $document->kind])->save();

            $this->audit(
                AuditEvent::CustomerUpdated,
                subject: $locked,
                before: ['document_number' => null],
                after: ['document_number' => $document->value],
                process: self::PROCESS,
            );

            return $locked;
        }));
    }
}
