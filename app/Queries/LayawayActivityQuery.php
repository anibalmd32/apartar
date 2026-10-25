<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Payment\ReceiptStatus;
use App\Models\Catalog;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\User;
use Carbon\CarbonInterface;

final class LayawayActivityQuery
{
    public const LIMIT = 12;

    /**
     * @return list<array{kind: string, title: string, detail: string|null, actor: string|null, at: string}>
     */
    public function for(Layaway $layaway, int $limit = self::LIMIT): array
    {
        $layaway->loadMissing([
            'catalog:id,organization_id,name',
            'receipts.reviewer:id,name',
            'payments.registeredBy:id,name',
            'installments',
        ]);

        $events = [];
        $catalog = $layaway->catalog;
        $events[] = $this->event('created', 'Apartado creado', $catalog instanceof Catalog ? 'Desde el catálogo «'.$catalog->name.'»' : null, null, $layaway->created_at);

        if ($layaway->activated_at !== null) {
            $events[] = $this->event('activated', 'Inicial pagada · apartado activo', null, null, $layaway->activated_at);
        }

        foreach ($layaway->receipts as $receipt) {
            $declared = MoneyFormatter::format(Money::fromDecimalString((string) $receipt->declared_amount, $receipt->declared_currency));
            $events[] = $this->event('receipt_submitted', 'Comprobante recibido · '.$declared, $receipt->payment_method->label(), null, $receipt->created_at);

            if ($receipt->status !== ReceiptStatus::PendingReview && $receipt->reviewed_at !== null) {
                $approved = $receipt->status === ReceiptStatus::Approved;
                $reviewer = $receipt->reviewer;
                $events[] = $this->event(
                    $approved ? 'receipt_approved' : 'receipt_rejected',
                    $approved ? 'Comprobante aprobado' : 'Comprobante rechazado',
                    $approved ? null : $receipt->rejection_reason,
                    $reviewer instanceof User ? $reviewer->name : null,
                    $receipt->reviewed_at,
                );
            }
        }

        foreach ($layaway->payments as $payment) {
            if ($payment->receipt_id !== null) {
                continue;
            }

            $registrar = $payment->registeredBy;
            $events[] = $this->event(
                'payment_recorded',
                'Pago registrado por el negocio · '.MoneyFormatter::format($payment->amount_cents_usd),
                $payment->note,
                $registrar instanceof User ? $registrar->name : null,
                $payment->settled_at,
            );
        }

        foreach ($layaway->installments as $installment) {
            if ($this->wentOverdue($installment)) {
                $events[] = $this->event('installment_overdue', 'Cuota '.$installment->number.' pasó a mora', null, null, $installment->due_date->addDay());
            }
        }

        if ($layaway->delivered_at !== null) {
            $events[] = $this->event('delivered', 'Entregado al cliente', null, null, $layaway->delivered_at);
        }

        if ($layaway->cancelled_at !== null) {
            $events[] = $this->event('cancelled', 'Apartado cancelado', $layaway->cancellation_reason, null, $layaway->cancelled_at);
        }

        usort($events, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($events, 0, $limit);
    }

    private function wentOverdue(Installment $installment): bool
    {
        if ($installment->status === InstallmentStatus::Overdue) {
            return true;
        }

        return $installment->status !== InstallmentStatus::Paid && $installment->due_date->isPast();
    }

    /**
     * @return array{kind: string, title: string, detail: string|null, actor: string|null, at: string}
     */
    private function event(string $kind, string $title, ?string $detail, ?string $actor, ?CarbonInterface $at): array
    {
        return ['kind' => $kind, 'title' => $title, 'detail' => $detail, 'actor' => $actor, 'at' => (string) $at?->toIso8601String()];
    }
}
