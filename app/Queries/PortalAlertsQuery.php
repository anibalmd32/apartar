<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Account\PortalContext;
use App\Domain\Payment\LayawayReceipts;
use App\Domain\Tenancy\TenantContext;
use App\Models\Layaway;
use Carbon\CarbonImmutable;

/**
 * Las alertas de la portada del portal (`REQ-LAY-023`).
 *
 * ## Sobre los bloques, no sobre la base
 *
 * Se calculan desde los `BusinessBalance` que ya compuso
 * `CustomerBalanceQuery`: las cuotas vencidas, lo que vence pronto y las
 * ventanas de la inicial salen de las mismas filas que pintan los bloques, con
 * el mismo `DueCalendar` de cada negocio —su «hoy» y su umbral—. Es lo que
 * hace que la alerta, el bloque y el cronograma no puedan contradecirse
 * (RB-1), y que el umbral de «pronto» sea el del primer recordatorio, igual
 * que el aviso (RB-4). Lo único que se pregunta aparte son los comprobantes,
 * a `LayawayReceipts`, una vez por negocio y con su tenant fijado.
 *
 * Los apartados terminales no dan alertas (RB-9): los bloques solo exponen los
 * vivos para esto.
 *
 * ## Las seis condiciones
 *
 *  - `down_payment_window`: un apartado que espera la inicial con la ventana
 *    abierta. **No** si ya tiene un comprobante en revisión: lo que se le dice
 *    entonces es `receipt_pending` (SP-3), y no se le apremia por un pago que
 *    ya hizo. Con la ventana ya cerrada y sin comprobante no hay alerta: es el
 *    rato hasta que el job de expiración pase.
 *  - `overdue`: cuotas sin cubrir con su fecha pasada. Una alerta por negocio
 *    si es uno; **una sola consolidada** si son varios (RB-5, SP-4).
 *  - `receipt_rejected`: un apartado cuyo último comprobante fue rechazado y
 *    sigue sin reintento.
 *  - `due_soon`: la primera cuota sin cubrir dentro del umbral, por negocio.
 *  - `receipt_pending`: los comprobantes en revisión, todos en una.
 *  - `claim_available` es de la sesión de cuenta, que todavía no existe
 *    (`T-076`, `T-077`). Con la sesión acotada nunca se emite: RB-10 la
 *    sustituye por la propuesta de conectar Google, que la portada pinta
 *    aparte. Que no pueda revelar si existe una ficha (RB-6) es, hoy, que no
 *    se construye.
 *
 * ## El orden
 *
 * El de RB-2, que es `PortalAlertKind::priority()`. Dentro de un mismo tipo,
 * la ventana que se cierra antes y el vencimiento más próximo van primero; el
 * resto conserva el orden de los bloques, que ya es «vence antes, arriba».
 * Se entregan **todas**: cuántas se ven de entrada (SP-1) es de quien pinta.
 */
final class PortalAlertsQuery
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly LayawayReceipts $receipts,
    ) {}

    /**
     * @param  list<BusinessBalance>  $blocks  los de la sesión, de `CustomerBalanceQuery`
     * @return list<PortalAlert>
     */
    public function forPortalContext(PortalContext $portal, array $blocks): array
    {
        $now = CarbonImmutable::now();
        $alerts = [];
        $overdueCount = 0;
        $overdueAmounts = [];
        $firstOverdue = null;
        $pendingCount = 0;
        $firstPending = null;

        foreach ($blocks as $block) {
            $organization = $block->organization;
            [$pending, $rejected] = $this->context->runFor($organization, fn (): array => [
                $this->receipts->pendingReviewCounts($block->active),
                $this->receipts->rejectedAwaitingRetry($block->active),
            ]);

            foreach ($block->awaitingDownPayment() as $layaway) {
                $expiresAt = $layaway->expires_at;

                if (($pending[(int) $layaway->getKey()] ?? 0) > 0 || $expiresAt === null || ! $expiresAt->greaterThan($now)) {
                    continue;
                }

                $alerts[] = PortalAlert::downPaymentWindow($organization, $layaway, (int) ceil($now->diffInSeconds($expiresAt) / 60));
            }

            $firstOverdueHere = $block->firstOverdueLayaway();

            if ($firstOverdueHere instanceof Layaway) {
                $overdueCount += $block->overdueInstallments();
                $overdueAmounts[] = ['organization' => $organization, 'amount' => $block->overdueAmount()];
                $firstOverdue ??= $firstOverdueHere;
            }

            foreach ($block->active as $layaway) {
                if (in_array((int) $layaway->getKey(), $rejected, true)) {
                    $alerts[] = PortalAlert::receiptRejected($organization, $layaway);
                }

                $count = $pending[(int) $layaway->getKey()] ?? 0;

                if ($count > 0) {
                    $pendingCount += $count;
                    $firstPending ??= $layaway;
                }
            }

            $soon = $block->dueSoon();

            if ($soon !== null) {
                $alerts[] = PortalAlert::dueSoon($organization, $soon['date'], $soon['amount'], $soon['layaway']);
            }
        }

        if ($firstOverdue instanceof Layaway && $overdueAmounts !== []) {
            $alerts[] = PortalAlert::overdue($overdueCount, $overdueAmounts, $firstOverdue);
        }

        if ($firstPending instanceof Layaway) {
            $alerts[] = PortalAlert::receiptPending($pendingCount, $firstPending);
        }

        return self::sorted($alerts);
    }

    /**
     * @param  list<PortalAlert>  $alerts
     * @return list<PortalAlert>
     */
    private static function sorted(array $alerts): array
    {
        usort($alerts, fn (PortalAlert $a, PortalAlert $b): int => [$a->kind->priority(), $a->minutesLeft ?? 0, $a->dueDate?->toDateString() ?? '']
            <=> [$b->kind->priority(), $b->minutesLeft ?? 0, $b->dueDate?->toDateString() ?? '']);

        return $alerts;
    }
}
