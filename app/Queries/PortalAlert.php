<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Account\PortalAlertKind;
use App\Domain\Money\Money;
use App\Models\Layaway;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Una alerta del portal, antes de pintarse (`REQ-LAY-023`).
 *
 * Lleva el **negocio** y no su nombre: `PortalAlertResource` la compone con el
 * tenant de ese negocio fijado, para que el importe salga con **su** tasa y la
 * fecha en **su** calendario, igual que el bloque del que viene. Cuando agrupa
 * varios negocios —solo `overdue`, RB-5— no hay un negocio, sino un importe
 * por cada uno, y cada parte se proyecta con la tasa del suyo antes de sumar.
 *
 * Cada alerta nombra también el apartado al que lleva su CTA (RB-7). Ninguna
 * lleva `id` (`REQ-LAY-020` RB-10): el apartado viaja por su código.
 *
 * Se construye con uno de sus constructores con nombre, que son las seis
 * formas de la unión discriminada `PortalAlert` del frontend.
 */
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
     * Las cuotas vencidas de uno o varios negocios (RB-3, RB-5): con uno, la
     * alerta es de ese negocio; con varios, de ninguno.
     *
     * @param  non-empty-list<array{organization: Organization, amount: Money}>  $amounts  una entrada por negocio
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

    /**
     * Los comprobantes en revisión, de todos los negocios en una sola alerta:
     * el contrato no nombra el negocio, solo cuántos.
     */
    public static function receiptPending(int $count, Layaway $first): self
    {
        return new self(PortalAlertKind::ReceiptPending, null, $first->code, $count);
    }
}
