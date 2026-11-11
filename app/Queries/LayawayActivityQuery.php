<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Payment\ReceiptStatus;
use App\Models\Catalog;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\User;
use App\Queries\Activity\ActivityEvent;
use App\Queries\Activity\AuditActivity;
use App\Queries\Activity\PaymentActivity;

final class LayawayActivityQuery
{
    public const LIMIT = 30;

    /**
     * @return list<array{kind: string, title: string, detail: string|null, actor: string|null, at: string, link: array{url: string, label: string}|null}>
     */
    public function for(Layaway $layaway, int $limit = self::LIMIT): array
    {
        $layaway->loadMissing([
            'catalog:id,organization_id,name',
            'createdBy:id,name',
            'receipts.reviewer:id,name',
            'installments',
        ]);

        $payments = Payment::query()
            ->withVoided()
            ->where('layaway_id', $layaway->getKey())
            ->with(['registeredBy:id,name', 'voidedBy:id,name', 'replaces.layaway:id,code', 'replaces.voidedBy:id,name', 'safeguard.recordedBy:id,name'])
            ->get();
        $audit = new AuditActivity($layaway, $payments);

        $events = [
            ActivityEvent::make('created', 'Apartado creado', $this->originOf($layaway->catalog, $layaway->createdBy), null, $layaway->created_at),
            ...$this->receipts($layaway),
            ...(new PaymentActivity)->for($layaway, $payments),
            ...$this->safeguardsBeforeAudit($payments, $audit),
            ...$audit->events(),
        ];

        if ($layaway->activated_at !== null) {
            $events[] = ActivityEvent::make(
                'activated',
                $layaway->terms()->requiresDownPayment() ? 'Inicial pagada · apartado activo' : 'Apartado activo · sin inicial',
                null,
                null,
                $layaway->activated_at,
            );
        }

        foreach ($layaway->installments as $installment) {
            if ($this->wentOverdue($installment)) {
                $events[] = ActivityEvent::make('installment_overdue', 'Cuota '.$installment->number.' quedó atrasada', null, null, $installment->due_date->addDay());
            }
        }

        if ($layaway->delivered_at !== null) {
            $events[] = ActivityEvent::make('delivered', 'Entregado al cliente', null, $audit->actorOf(AuditEvent::LayawayDelivered), $layaway->delivered_at);
        }

        if ($layaway->cancelled_at !== null) {
            $events[] = ActivityEvent::make('cancelled', 'Apartado cancelado', $layaway->cancellation_reason, $audit->actorOf(AuditEvent::LayawayCancelled), $layaway->cancelled_at);
        }

        usort($events, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($events, 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function receipts(Layaway $layaway): array
    {
        $events = [];

        foreach ($layaway->receipts as $receipt) {
            $declared = MoneyFormatter::format(Money::fromDecimalString((string) $receipt->declared_amount, $receipt->declared_currency));
            $events[] = ActivityEvent::make('receipt_submitted', 'Comprobante recibido · '.$declared, $receipt->payment_method->label(), null, $receipt->created_at);

            if ($receipt->status !== ReceiptStatus::PendingReview && $receipt->reviewed_at !== null) {
                $approved = $receipt->status === ReceiptStatus::Approved;
                $events[] = ActivityEvent::make(
                    $approved ? 'receipt_approved' : 'receipt_rejected',
                    $approved ? 'Comprobante aprobado' : 'Comprobante rechazado',
                    $approved ? null : $receipt->rejection_reason,
                    $receipt->reviewer instanceof User ? $receipt->reviewer->name : null,
                    $receipt->reviewed_at,
                );
            }
        }

        return $events;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Payment>  $payments
     * @return list<array<string, mixed>>
     */
    private function safeguardsBeforeAudit(\Illuminate\Database\Eloquent\Collection $payments, AuditActivity $audit): array
    {
        $events = [];

        foreach ($payments as $payment) {
            $safeguard = $payment->safeguard;

            if ($safeguard === null || $audit->recordedSafeguard((int) $payment->getKey())) {
                continue;
            }

            $events[] = ActivityEvent::make(
                'safeguard_recorded',
                'Resguardo registrado · '.$safeguard->destination->label(),
                'Pago de '.MoneyFormatter::format($payment->amount_cents_usd).($safeguard->reference === null ? '' : '. ref. '.$safeguard->reference),
                $safeguard->recordedBy?->name,
                $safeguard->updated_at,
            );
        }

        return $events;
    }

    private function originOf(?Catalog $catalog, ?User $creator): ?string
    {
        return match (true) {
            $catalog instanceof Catalog => 'Desde el catálogo «'.$catalog->name.'»',
            $creator instanceof User => 'Creado por '.$creator->name.' desde el backoffice',
            default => null,
        };
    }

    private function wentOverdue(Installment $installment): bool
    {
        if ($installment->status === InstallmentStatus::Overdue) {
            return true;
        }

        return $installment->status !== InstallmentStatus::Paid && $installment->due_date->isPast();
    }
}
