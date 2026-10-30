<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Customer\NameDivergence;
use App\Domain\Customer\TermsConsent;
use App\Domain\Tenancy\MissingTenantContextException;
use App\Models\Customer;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\DocumentNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ResolveCustomerAction
{
    use LogsSensitiveActivity;

    public const PROCESS = 'formulario público de apartado';

    /**
     * @var list<string>
     */
    private const AUDITED_FIELDS = ['full_name', 'phone', 'email', 'terms_accepted_at', 'terms_ip_address'];

    /**
     * @throws MissingTenantContextException
     */
    public function handle(CustomerContactData $contact, TermsConsent $consent): Customer
    {
        return DB::transaction(function () use ($contact, $consent): Customer {
            $collision = null;

            if (! $this->exists($contact->documentNumber)) {
                try {
                    return $this->create($contact, $consent);
                } catch (UniqueConstraintViolationException $exception) {
                    $collision = $exception;
                }
            }

            $customer = $this->lockedFind($contact->documentNumber);

            if (! $customer instanceof Customer) {
                throw $collision ?? new LogicException(sprintf(
                    'La ficha %s existía al leerla y había desaparecido al bloquearla.',
                    $contact->documentNumber->value,
                ));
            }

            return $this->reuse($customer, $contact, $consent);
        });
    }

    /**
     * @throws MissingTenantContextException
     */
    public function declaredBy(CustomerContactData $contact, TermsConsent $consent, User $declarant): Customer
    {
        return DB::transaction(function () use ($contact, $consent, $declarant): Customer {
            $collision = null;

            if (! $this->exists($contact->documentNumber)) {
                try {
                    return $this->create($contact, $consent, $declarant);
                } catch (UniqueConstraintViolationException $exception) {
                    $collision = $exception;
                }
            }

            return $this->lockedFind($contact->documentNumber) ?? throw $collision ?? new LogicException(sprintf(
                'La ficha %s existía al leerla y había desaparecido al bloquearla.',
                $contact->documentNumber->value,
            ));
        });
    }

    private function exists(DocumentNumber $document): bool
    {
        return Customer::query()->where('document_number', $document->value)->exists();
    }

    private function lockedFind(DocumentNumber $document): ?Customer
    {
        return Customer::query()->where('document_number', $document->value)->lockForUpdate()->first();
    }

    private function create(CustomerContactData $contact, TermsConsent $consent, ?User $declarant = null): Customer
    {
        return DB::transaction(function () use ($contact, $consent, $declarant): Customer {
            $customer = new Customer;

            $customer->forceFill([
                'document_number' => $contact->documentNumber->value,
                'document_kind' => $contact->documentNumber->kind,
                ...$this->contactAttributes($contact, $consent),
                'terms_declared_by_user_id' => $declarant?->getKey(),
            ])->save();

            return $customer;
        });
    }

    private function reuse(Customer $customer, CustomerContactData $contact, TermsConsent $consent): Customer
    {
        $before = $this->auditedFieldsOf($customer);

        $customer->forceFill($this->contactAttributes($contact, $consent))->save();

        $after = $this->auditedFieldsOf($customer);

        if ($before !== $after) {
            $this->audit(
                AuditEvent::CustomerContactUpdated,
                subject: $customer,
                before: $before,
                after: $after,
                process: self::PROCESS,
            );
        }

        if (NameDivergence::isRadical((string) $before['full_name'], $customer->full_name)) {
            $this->audit(
                AuditEvent::CustomerIdentityConflict,
                subject: $customer,
                before: ['full_name' => $before['full_name']],
                after: ['full_name' => $customer->full_name],
                process: self::PROCESS,
            );
        }

        return $customer;
    }

    /**
     * @return array<string, mixed>
     */
    private function contactAttributes(CustomerContactData $contact, TermsConsent $consent): array
    {
        $attributes = [
            'full_name' => $contact->fullName,
            'phone' => $contact->phone->value,
            'terms_accepted_at' => $consent->acceptedAt,
            'terms_ip_address' => $consent->ipAddress,
            'terms_declared_by_user_id' => null,
        ];

        if ($contact->email !== null) {
            $attributes['email'] = $contact->email;
        }

        return $attributes;
    }

    /**
     * @return array<string, string|null>
     */
    private function auditedFieldsOf(Customer $customer): array
    {
        $fields = [];

        foreach (self::AUDITED_FIELDS as $field) {
            $value = $customer->getAttribute($field);

            $fields[$field] = match (true) {
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                $value === null => null,
                default => (string) $value,
            };
        }

        return $fields;
    }
}
