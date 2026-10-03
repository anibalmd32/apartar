<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Money\Money;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Layaway;
use App\Models\Organization;
use Carbon\CarbonInterface;
use LogicException;

/**
 * Lo que una persona tiene con **un** negocio, para su bloque del portal
 * (`REQ-LAY-020` RB-2, RB-5, RB-6).
 *
 * Separa los apartados vivos del historial y agrega solo los vivos: lo
 * comprometido, lo pagado, lo que falta, las cuotas en mora y el próximo
 * vencimiento. Todo sale de los **balances materializados** de cada apartado
 * (`REQ-PAY-008`), no de recorrer pagos, y sin una consulta más: los apartados
 * ya vienen cargados por `CustomerBalanceQuery`.
 *
 * Nunca mezcla negocios: se construye con los apartados de una organización,
 * y lo afirma.
 */
final readonly class BusinessBalance
{
    /** @var list<Layaway> */
    public array $active;

    /** @var list<Layaway> */
    public array $history;

    /**
     * @param  list<Layaway>  $layaways  de **esta** organización, con `overdue_installments_count`
     */
    public function __construct(public Organization $organization, array $layaways)
    {
        $active = [];
        $history = [];

        foreach ($layaways as $layaway) {
            if ((int) $layaway->organization_id !== (int) $organization->getKey()) {
                throw new LogicException('Un bloque del portal es de un solo negocio, y llegó un apartado de otro (REQ-LAY-020 RB-2).');
            }

            if ($layaway->status->isTerminal()) {
                $history[] = $layaway;
            } else {
                $active[] = $layaway;
            }
        }

        $this->active = $active;
        $this->history = $history;
    }

    public function committed(): Money
    {
        return $this->sum(fn (Layaway $layaway): Money => $layaway->total_cents);
    }

    public function paid(): Money
    {
        return $this->sum(fn (Layaway $layaway): Money => $layaway->paid_cents);
    }

    public function remaining(): Money
    {
        return $this->sum(fn (Layaway $layaway): Money => $layaway->remaining_cents);
    }

    public function overdueInstallments(): int
    {
        return (int) array_sum(array_map(fn (Layaway $layaway): int => self::overdueCountOf($layaway), $this->active));
    }

    /**
     * El vencimiento más próximo entre los vivos, o `null` si ninguno tiene
     * cuotas por pagar —uno que espera la inicial todavía no tiene fechas—.
     */
    public function nextDueDate(): ?CarbonInterface
    {
        $dates = array_values(array_filter(array_map(fn (Layaway $layaway): ?CarbonInterface => $layaway->next_due_date, $this->active)));

        if ($dates === []) {
            return null;
        }

        usort($dates, fn (CarbonInterface $a, CarbonInterface $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        return $dates[0];
    }

    /**
     * Si el negocio dejó de operar en la plataforma (SP-9). Una suspensión
     * **no** cuenta: el cliente no tiene por qué saber que el negocio no le
     * paga a la plataforma (RB-12).
     */
    public function businessIsClosed(): bool
    {
        return $this->organization->status === OrganizationStatus::Closed;
    }

    public static function overdueCountOf(Layaway $layaway): int
    {
        return (int) $layaway->getAttribute('overdue_installments_count');
    }

    /**
     * @param  callable(Layaway): Money  $amount
     */
    private function sum(callable $amount): Money
    {
        $total = new Money(0);

        foreach ($this->active as $layaway) {
            $total = $total->add($amount($layaway));
        }

        return $total;
    }
}
