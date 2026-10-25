<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Account\PortalAlertKind;
use App\Domain\Money\Money;
use App\Models\Layaway;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use LogicException;

final readonly class PortalAlert
{
    /**
     * @param  list<array{organization: Organization, amount: Money}>  $amounts
     */
    private function __construct(
        public PortalAlertKind $kind,
        public ?Organization $organization,
        public string $layawayCode,
        public ?int $count = null,
        public array $amounts = [],
        public ?CarbonImmutable $dueDate = null,
        public ?int $minutesLeft = null,
    ) {}

    public static function downPaymentWindow(Organization $organization, Layaway $layaway, int $minutesLeft): self
    {
        return new self(PortalAlertKind::DownPaymentWindow, $organization, $layaway->code, minutesLeft: $minutesLeft);
    }

    /**
     * @param  non-empty-list<array{organization: Organization, amount: Money}>  $amounts
     */
    public static function overdue(int $count, array $amounts, Layaway $first): self
    {
        $organizations = array_unique(array_map(fn (array $part): int => (int) $part['organization']->getKey(), $amounts));

        if (count($organizations) !== count($amounts)) {
            throw new LogicException('Una alerta de cuotas vencidas lleva un importe por negocio, no varios del mismo.');
        }

        return new self(PortalAlertKind::Overdue, count($amounts) === 1 ? $amounts[0]['organization'] : null, $first->code, $count, $amounts);
    }

    public static function receiptRejected(Organization $organization, Layaway $layaway): self
    {
        return new self(PortalAlertKind::ReceiptRejected, $organization, $layaway->code);
    }

    public static function dueSoon(Organization $organization, CarbonImmutable $dueDate, Money $amount, Layaway $layaway): self
    {
        return new self(PortalAlertKind::DueSoon, $organization, $layaway->code, amounts: [['organization' => $organization, 'amount' => $amount]], dueDate: $dueDate);
    }

    public static function receiptPending(int $count, Layaway $first): self
    {
        return new self(PortalAlertKind::ReceiptPending, null, $first->code, $count);
    }

    public static function claimAvailable(Organization $organization): self
    {
        return new self(PortalAlertKind::ClaimAvailable, $organization, '');
    }
}
