<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Subscription\InvoiceKind;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\OrganizationDocumentRequiredException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Support\Auditing\LogsSensitiveActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Emite una factura de la suscripción (`REQ-SUB-006`, `T-069`).
 *
 * La llaman tres sitios, cada uno con su período y su vencimiento
 * (`InvoiceKind`): subir de plan (`ChangePlanAction`, vence hoy: el plan ya se
 * usa), la renovación siete días antes del fin del período
 * (`IssueRenewalInvoices`, vence al acabar el período, RB-8) y, en `T-086`, la
 * plataforma a mano (`REQ-PLT-004` RB-5).
 *
 * Tres reglas que viven aquí y no en quien llama:
 *
 *  - **Un plan sin precio no se factura** (RB-9: Free nunca genera
 *    facturas). Lanza en vez de emitir una factura de cero: quien llame tiene
 *    que preguntar antes, y si no lo hace es un error de programación.
 *  - **La primera factura exige el RIF** (RB-5, SP-4): sin él lanza
 *    `OrganizationDocumentRequiredException`, que se le enseña al negocio.
 *  - **Lo emitido es inmutable** (RB-3): importe y plan se copian **ahora**,
 *    y cambiar el precio del plan mañana no toca esta factura
 *    (`REQ-SUB-001` RB-7).
 *
 * Corre en la organización de la suscripción —el global scope pone la de la
 * factura— y dentro de la transacción de quien la llame, si la hay: subir de
 * plan y emitir su factura son una sola cosa.
 */
final class IssueInvoiceAction
{
    use LogsSensitiveActivity;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  Model|null  $actor  quién la emite; nulo es el sistema (la renovación)
     *
     * @throws LogicException si el plan no tiene precio (RB-9)
     * @throws OrganizationDocumentRequiredException si es la primera y falta el RIF (RB-5)
     */
    public function handle(
        Subscription $subscription,
        Plan $plan,
        InvoiceKind $kind,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        CarbonImmutable $dueDate,
        ?Model $actor = null,
    ): SubscriptionInvoice {
        if ($plan->price_cents <= 0) {
            throw new LogicException(sprintf('El plan %s no tiene precio y no se factura (REQ-SUB-006 RB-9).', $plan->code));
        }

        $organization = $this->context->organization();

        if ((int) $organization->getKey() !== $subscription->organization_id) {
            throw new LogicException('La factura se emite en la organización de su suscripción.');
        }

        return DB::transaction(function () use ($subscription, $plan, $kind, $periodStart, $periodEnd, $dueDate, $actor, $organization): SubscriptionInvoice {
            self::assertCanBeInvoiced($organization->document);

            $invoice = new SubscriptionInvoice;
            $invoice->forceFill([
                'organization_id' => $subscription->organization_id,
                'plan_id' => $plan->getKey(),
                'kind' => $kind,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'amount_cents' => $plan->price_cents,
                'paid_cents' => 0,
                'currency' => 'USD',
                'status' => InvoiceStatus::Pending,
                'due_date' => $dueDate->toDateString(),
                'issued_at' => CarbonImmutable::now(),
            ])->save();

            // RB-10 · toda emisión se audita, y el negocio la ve en su
            // historial de facturas.
            $this->audit(
                AuditEvent::SubscriptionInvoiceIssued,
                subject: $invoice,
                actor: $actor,
                after: [
                    'plan' => $plan->code,
                    'kind' => $kind->value,
                    'amount_cents' => $plan->price_cents,
                    'period_start' => $periodStart->toIso8601String(),
                    'period_end' => $periodEnd->toIso8601String(),
                    'due_date' => $dueDate->toDateString(),
                ],
                process: $actor === null ? 'subscriptions:issue-renewal-invoices' : null,
            );

            return $invoice;
        });
    }

    /**
     * RB-5 · la primera factura exige el RIF; con una ya emitida (aunque se
     * anulara después), el negocio ya pasó por aquí.
     *
     * Pública para que `ChangePlanAction` pregunte **antes** de tocar nada:
     * el RIF se pide antes de subir de plan, no a mitad.
     *
     * @throws OrganizationDocumentRequiredException
     */
    public static function assertCanBeInvoiced(?string $document): void
    {
        if (($document === null || trim($document) === '') && SubscriptionInvoice::query()->doesntExist()) {
            throw new OrganizationDocumentRequiredException;
        }
    }
}
