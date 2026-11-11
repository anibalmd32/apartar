<?php

declare(strict_types=1);

namespace App\Queries\Activity;

use App\Domain\Money\MoneyFormatter;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class PaymentActivity
{
    /**
     * @param  Collection<int, Payment>  $payments
     * @return list<array<string, mixed>>
     */
    public function for(Layaway $layaway, Collection $payments): array
    {
        $replacements = Payment::query()
            ->withVoided()
            ->whereIn('replaces_payment_id', $payments->whereNotNull('voided_at')->modelKeys())
            ->with('layaway:id,code')
            ->get()
            ->keyBy('replaces_payment_id');

        $events = [];

        foreach ($payments as $payment) {
            $original = $payment->replaces;

            if ($original instanceof Payment) {
                $events[] = $this->replacing($layaway, $payment, $original);
            } elseif ($payment->receipt_id === null) {
                $events[] = ActivityEvent::make(
                    'payment_recorded',
                    'Pago registrado por el negocio · '.MoneyFormatter::format($payment->amount_cents_usd),
                    $payment->note,
                    $this->name($payment->registeredBy),
                    $payment->settled_at,
                );
            }

            if ($payment->voided_at !== null) {
                $voided = $this->voided($layaway, $payment, $replacements->get($payment->getKey()));

                if ($voided !== null) {
                    $events[] = $voided;
                }
            }
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    private function replacing(Layaway $layaway, Payment $payment, Payment $original): array
    {
        $amount = MoneyFormatter::format($payment->amount_cents_usd);
        $actor = $this->name($original->voidedBy);

        if ((int) $original->layaway_id === (int) $layaway->getKey()) {
            $before = MoneyFormatter::format($original->amount_cents_usd);

            return ActivityEvent::make(
                'payment_edited',
                $before === $amount ? 'Pago editado · '.$amount : sprintf('Pago editado · %s → %s', $before, $amount),
                ActivityEvent::reason($original->void_reason),
                $actor,
                $original->voided_at,
            );
        }

        $from = $original->layaway;

        return ActivityEvent::make(
            'payment_transferred_in',
            'Recibió un pago transferido · '.$amount,
            trim(sprintf('Estaba en el apartado %s. %s', $from->code ?? '', ActivityEvent::reason($original->void_reason) ?? '')),
            $actor,
            $original->voided_at,
            $from instanceof Layaway ? route('layaways.show', $from) : null,
            $from?->code,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function voided(Layaway $layaway, Payment $payment, ?Payment $replacement): ?array
    {
        $amount = MoneyFormatter::format($payment->amount_cents_usd);
        $actor = $this->name($payment->voidedBy);

        if ($replacement === null) {
            return ActivityEvent::make('payment_voided', 'Pago anulado · '.$amount, ActivityEvent::reason($payment->void_reason), $actor, $payment->voided_at);
        }

        if ((int) $replacement->layaway_id === (int) $layaway->getKey()) {
            return null;
        }

        $to = $replacement->layaway;

        return ActivityEvent::make(
            'payment_transferred_out',
            sprintf('Pago transferido al apartado %s · %s', $to->code ?? '', $amount),
            ActivityEvent::reason($payment->void_reason),
            $actor,
            $payment->voided_at,
            $to instanceof Layaway ? route('layaways.show', $to) : null,
            $to?->code,
        );
    }

    private function name(?User $user): ?string
    {
        return $user?->name;
    }
}
