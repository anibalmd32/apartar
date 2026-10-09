<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Money\Money;
use App\Models\Customer;
use App\Support\DocumentNumber;
use App\Support\PhoneNumber;

/**
 * Una fila del directorio (`REQ-BOF-040` RB-2): la ficha con los agregados que
 * `CustomerDirectoryQuery` le puso al lado.
 *
 * De la cuenta vinculada solo sale **si la hay** (RB-8): nunca su `account_id`
 * ni nada de ella (`REQ-ACC-007` RB-5).
 */
final readonly class CustomerDirectoryRow
{
    public function __construct(
        public int $id,
        public string $name,
        public string $document,
        public string $phone,
        public string $phoneLabel,
        public bool $hasLinkedAccount,
        public int $activeLayaways,
        public Money $committed,
        public Money $paid,
        public Money $remaining,
        public int $overdueInstallments,
        public bool $inArrears,
        public ?string $nextDueDate,
    ) {}

    public static function of(Customer $customer): self
    {
        $phone = PhoneNumber::tryFromInput($customer->phone);
        $document = DocumentNumber::tryFromInput($customer->document_number);
        $next = $customer->getAttribute('next_due_date');

        return new self(
            id: (int) $customer->getKey(),
            name: $customer->full_name,
            document: $document instanceof DocumentNumber ? $document->formatted() : $customer->document_number,
            phone: $customer->phone,
            phoneLabel: $phone instanceof PhoneNumber ? $phone->formatted() : $customer->phone,
            hasLinkedAccount: $customer->account_id !== null,
            activeLayaways: (int) $customer->getAttribute('active_layaways'),
            committed: new Money((int) $customer->getAttribute('committed_cents')),
            paid: new Money((int) $customer->getAttribute('paid_cents')),
            remaining: new Money((int) $customer->getAttribute('remaining_cents')),
            overdueInstallments: (int) $customer->getAttribute('overdue_installments'),
            inArrears: (bool) $customer->getAttribute('in_arrears'),
            nextDueDate: is_string($next) && $next !== '' ? substr($next, 0, 10) : null,
        );
    }
}
