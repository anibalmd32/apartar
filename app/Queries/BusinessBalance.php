<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Layaway\DueCalendar;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Money;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Layaway;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;

/**
 * Lo que una persona tiene con **un** negocio, para su bloque del portal
 * (`REQ-LAY-020` RB-2, RB-5, RB-6) y sus alertas (`REQ-LAY-023`).
 *
 * Separa los apartados vivos del historial y agrega solo los vivos: lo
 * comprometido, lo pagado, lo que falta, lo vencido, lo que vence pronto y el
 * próximo vencimiento. Todo sale de los **balances materializados** de cada
 * apartado (`REQ-PAY-008`) y de las subconsultas de sus cuotas, no de recorrer
 * pagos, y sin una consulta más: los apartados ya vienen cargados por
 * `CustomerBalanceQuery`. Los terminales no cuentan para nada de eso
 * (`REQ-LAY-023` RB-9).
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
     * @param  list<Layaway>  $layaways  de **esta** organización, con las subconsultas de `CustomerBalanceQuery`
     * @param  DueCalendar  $calendar  el de **esta** organización, con el que se contaron los vencimientos
     */
    public function __construct(public Organization $organization, array $layaways, public DueCalendar $calendar)
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

    /**
     * Las cuotas sin cubrir con la fecha ya pasada en el calendario del
     * negocio, estén o no en mora todavía (`DueCalendar`).
     */
    public function overdueInstallments(): int
    {
        return (int) array_sum(array_map(fn (Layaway $layaway): int => self::overdueCountOf($layaway), $this->active));
    }

    /**
     * Lo que falta de esas cuotas.
     */
    public function overdueAmount(): Money
    {
        return $this->sum(fn (Layaway $layaway): Money => new Money((int) $layaway->getAttribute('overdue_pending_cents')));
    }

    /**
     * El primer apartado vivo con alguna cuota vencida, para llevar a la
     * persona a él.
     */
    public function firstOverdueLayaway(): ?Layaway
    {
        foreach ($this->active as $layaway) {
            if (self::overdueCountOf($layaway) > 0) {
                return $layaway;
            }
        }

        return null;
    }

    /**
     * La primera fecha dentro del umbral de «pronto» con alguna cuota sin
     * cubrir, lo que falta de las cuotas de ese día y el apartado al que
     * pertenece la primera; `null` si no vence nada pronto.
     *
     * @return array{date: CarbonImmutable, amount: Money, layaway: Layaway}|null
     */
    public function dueSoon(): ?array
    {
        $soon = null;

        foreach ($this->active as $layaway) {
            $date = $layaway->getAttribute('soon_due_date');

            if (! is_string($date)) {
                continue;
            }

            $day = $this->calendar->day(CarbonImmutable::parse($date));
            $amount = new Money((int) $layaway->getAttribute('soon_pending_cents'));

            if ($soon === null || $day->lessThan($soon['date'])) {
                $soon = ['date' => $day, 'amount' => $amount, 'layaway' => $layaway];
            } elseif ($day->equalTo($soon['date'])) {
                $soon['amount'] = $soon['amount']->add($amount);
            }
        }

        return $soon;
    }

    /**
     * Los apartados que esperan la inicial, por orden de cuál se cierra antes.
     *
     * @return list<Layaway>
     */
    public function awaitingDownPayment(): array
    {
        $waiting = array_values(array_filter($this->active, fn (Layaway $layaway): bool => $layaway->status === LayawayStatus::PendingDownPayment));
        usort($waiting, fn (Layaway $a, Layaway $b): int => ($a->expires_at?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->expires_at?->getTimestamp() ?? PHP_INT_MAX));

        return $waiting;
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
