<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Domain\Money\Money;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Queries\DashboardMetricsQuery;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El panel del negocio (`REQ-BOF-044`): qué requiere atención hoy, los avisos
 * de lo que está costando ventas o dinero, el consumo del plan y la tasa.
 *
 * Las cifras llegan en centavos de `DashboardMetricsQuery` —cacheadas 60 s— y
 * aquí se proyectan a la moneda de la pantalla con la tasa de **ahora**: la
 * caché no congela una conversión.
 *
 * De todos los roles, como el menú: cada tarjeta enlaza a una lista que su
 * política decide si se ve. Sobrevive a la suspensión (RB-10): el aviso de
 * pago va primero.
 */
final class DashboardController extends Controller
{
    use ProjectsMoney;

    public function __construct(
        private readonly TenantContext $context,
        private readonly DashboardMetricsQuery $metrics,
    ) {}

    public function __invoke(): Response
    {
        $metrics = $this->metrics->compute($this->context->organization());
        $collected = $metrics['collectedThisMonthCents'] ?? null;
        $week = $metrics['dueThisWeek'] ?? null;

        return Inertia::render('backoffice/Dashboard', [
            'metrics' => [
                ...$metrics,
                'collectedThisMonth' => is_int($collected) ? $this->money(new Money($collected)) : null,
                'dueThisWeek' => is_array($week) ? ['count' => $week['count'], 'amount' => $this->money(new Money((int) $week['pendingCents']))] : null,
                'warnings' => $this->suspendedFirst(is_array($metrics['warnings'] ?? null) ? $metrics['warnings'] : []),
            ],
            'links' => [
                'receipts' => route('receipts.index', ['status' => 'pending_review']),
                'dueThisWeek' => route('collections.index', ['preset' => 'due_within_7_days']),
                'customersOverdue' => route('customers.index', ['overdue' => '1']),
                'longOverdue' => route('collections.index', ['preset' => 'overdue_over_15_days']),
                'completedNotDelivered' => route('collections.index', ['preset' => 'completed_not_delivered']),
            ],
        ]);
    }

    /**
     * RB-10, SP-7 · con la suscripción sin pagar, ese aviso va el primero.
     *
     * @param  list<array<string, mixed>>  $warnings
     * @return list<array<string, mixed>>
     */
    private function suspendedFirst(array $warnings): array
    {
        usort($warnings, fn (array $a, array $b): int => ($b['kind'] === 'invoice_overdue') <=> ($a['kind'] === 'invoice_overdue'));

        return $warnings;
    }
}
