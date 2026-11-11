<?php

declare(strict_types=1);

namespace App\Queries\Activity;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Safeguard\SafeguardDestination;
use App\Models\AuditEntry;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\User;
use App\Support\HumanDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class AuditActivity
{
    private const EVENTS = [
        AuditEvent::PaymentReallocated,
        AuditEvent::InstallmentRescheduled,
        AuditEvent::SafeguardRecorded,
        AuditEvent::SafeguardRemoved,
        AuditEvent::LayawayDelivered,
        AuditEvent::LayawayCancelled,
    ];

    /**
     * @var Collection<int, AuditEntry>
     */
    private Collection $entries;

    /**
     * @var array<int, string>
     */
    private array $names = [];

    /**
     * @param  Collection<int, Payment>  $payments
     */
    public function __construct(Layaway $layaway, private readonly Collection $payments)
    {
        $paymentType = (new Payment)->getMorphClass();
        $layawayType = $layaway->getMorphClass();

        $this->entries = AuditEntry::query()
            ->audited()
            ->whereIn('event', array_map(fn (AuditEvent $event): string => $event->value, self::EVENTS))
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $query): Builder => $query->where('subject_type', $layawayType)->where('subject_id', $layaway->getKey()))
                ->orWhere(fn (Builder $query): Builder => $query->where('subject_type', $paymentType)->whereIn('subject_id', $payments->modelKeys())))
            ->orderBy('id')
            ->get();

        $userType = (new User)->getMorphClass();
        $causers = $this->entries->where('causer_type', $userType)->pluck('causer_id')->filter()->unique()->values()->all();
        $this->names = $causers === [] ? [] : User::query()->whereIn('id', $causers)->pluck('name', 'id')->all();
    }

    public function actorOf(AuditEvent $event): ?string
    {
        $entry = $this->entries->last(fn (AuditEntry $entry): bool => $entry->event === $event->value);

        return $entry instanceof AuditEntry ? $this->actor($entry) : null;
    }

    public function recordedSafeguard(int $paymentId): bool
    {
        return $this->entries->contains(fn (AuditEntry $entry): bool => $entry->event === AuditEvent::SafeguardRecorded->value && (int) $entry->subject_id === $paymentId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        $events = [];

        foreach ($this->entries as $entry) {
            $changed = $this->changed($entry);
            $payment = $this->payments->firstWhere('id', (int) $entry->subject_id);
            $amount = $payment instanceof Payment ? MoneyFormatter::format($payment->amount_cents_usd) : null;
            $at = $entry->created_at === null ? null : CarbonImmutable::instance($entry->created_at);

            $event = match ($entry->event) {
                AuditEvent::PaymentReallocated->value => ActivityEvent::make(
                    'payment_reallocated',
                    'Pago reasignado entre cuotas'.($amount === null ? '' : ' · '.$amount),
                    $this->join([$this->allocations($changed['allocations'] ?? null), $this->motive($changed)]),
                    $this->actor($entry),
                    $at,
                ),
                AuditEvent::InstallmentRescheduled->value => $this->rescheduled($entry, $changed, $at),
                AuditEvent::SafeguardRecorded->value => $this->safeguard($entry, $changed, $payment, $at),
                AuditEvent::SafeguardRemoved->value => ActivityEvent::make(
                    'safeguard_removed',
                    'Resguardo borrado',
                    $amount === null ? null : 'Pago de '.$amount,
                    $this->actor($entry),
                    $at,
                ),
                default => null,
            };

            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $changed
     * @return array<string, mixed>|null
     */
    private function rescheduled(AuditEntry $entry, array $changed, ?CarbonImmutable $at): ?array
    {
        foreach ($changed as $key => $values) {
            if (preg_match('/^installments\.(\d+)\.due_date$/', (string) $key, $match) !== 1 || ! is_array($values)) {
                continue;
            }

            return ActivityEvent::make(
                'installment_rescheduled',
                sprintf('Cuota %d reprogramada', (int) $match[1]),
                $this->join([sprintf('Del %s al %s', $this->day($values['before'] ?? null), $this->day($values['after'] ?? null)), $this->motive($changed)]),
                $this->actor($entry),
                $at,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $changed
     * @return array<string, mixed>
     */
    private function safeguard(AuditEntry $entry, array $changed, ?Payment $payment, ?CarbonImmutable $at): array
    {
        $destination = $changed['destination']['after'] ?? null;
        $label = is_string($destination) ? SafeguardDestination::tryFrom($destination)?->label() : $payment?->safeguard?->destination->label();
        $edited = ! array_key_exists('destination', $changed) || ($changed['destination']['before'] ?? null) !== null;
        $reference = $changed['reference']['after'] ?? null;
        $amount = $payment instanceof Payment ? MoneyFormatter::format($payment->amount_cents_usd) : null;

        return ActivityEvent::make(
            $edited ? 'safeguard_edited' : 'safeguard_recorded',
            ($edited ? 'Resguardo editado' : 'Resguardo registrado').($label === null ? '' : ' · '.$label),
            $this->join([$amount === null ? null : 'Pago de '.$amount, is_string($reference) ? 'ref. '.$reference : null]),
            $this->actor($entry),
            $at,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function changed(AuditEntry $entry): array
    {
        $changed = $entry->properties->get('changed', []);

        return is_array($changed) ? $changed : [];
    }

    /**
     * @param  array<string, mixed>  $changed
     */
    private function motive(array $changed): ?string
    {
        $reason = $changed['reason']['after'] ?? null;

        return is_string($reason) && $reason !== '' ? 'Motivo: '.$reason : null;
    }

    private function allocations(mixed $values): ?string
    {
        if (! is_array($values)) {
            return null;
        }

        $describe = function (mixed $rows): string {
            $parts = [];

            foreach (is_array($rows) ? $rows : [] as $row) {
                if (is_array($row) && isset($row['installment'], $row['cents'])) {
                    $parts[] = sprintf('cuota %d %s', (int) $row['installment'], MoneyFormatter::format(new Money((int) $row['cents'])));
                }
            }

            return $parts === [] ? 'nada' : implode(', ', $parts);
        };

        return sprintf('Antes: %s. Ahora: %s', $describe($values['before'] ?? null), $describe($values['after'] ?? null));
    }

    private function day(mixed $value): string
    {
        return is_string($value) ? HumanDate::short(CarbonImmutable::parse($value)) : '—';
    }

    /**
     * @param  list<string|null>  $parts
     */
    private function join(array $parts): ?string
    {
        $parts = array_values(array_filter($parts, fn (?string $part): bool => $part !== null && $part !== ''));

        return $parts === [] ? null : implode('. ', $parts);
    }

    private function actor(AuditEntry $entry): ?string
    {
        return $entry->causer_id === null ? null : ($this->names[(int) $entry->causer_id] ?? null);
    }
}
