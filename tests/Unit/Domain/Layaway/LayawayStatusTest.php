<?php

declare(strict_types=1);

use App\Domain\Layaway\LayawayStatus;

/**
 * @return array<string, array{0: LayawayStatus, 1: LayawayStatus, 2: bool}>
 */
function transitionMatrix(): array
{
    $valid = [
        'pending_down_payment' => ['active', 'expired', 'cancelled'],
        'active' => ['overdue', 'completed', 'cancelled'],
        'overdue' => ['active', 'completed', 'cancelled'],
        'completed' => ['active', 'overdue'],
        'cancelled' => [],
        'expired' => [],
    ];

    $cells = [];

    foreach (LayawayStatus::cases() as $from) {
        foreach (LayawayStatus::cases() as $to) {
            $cells["{$from->value} → {$to->value}"] = [$from, $to, in_array($to->value, $valid[$from->value], true)];
        }
    }

    return $cells;
}

it('tiene exactamente seis estados, sin intermedios', function (): void {
    expect(array_map(fn (LayawayStatus $s): string => $s->value, LayawayStatus::cases()))
        ->toBe(['pending_down_payment', 'active', 'overdue', 'completed', 'cancelled', 'expired']);
});

it('admite exactamente las transiciones válidas', function (LayawayStatus $from, LayawayStatus $to, bool $valid): void {
    expect($from->canTransitionTo($to))->toBe($valid)
        ->and(in_array($to, $from->allowedTransitions(), true))->toBe($valid);
})->with(transitionMatrix());

it('tiene once celdas válidas de treinta y seis', function (): void {
    expect(array_filter(transitionMatrix(), fn (array $cell): bool => $cell[2]))->toHaveCount(11)
        ->and(transitionMatrix())->toHaveCount(36);
});

it('solo deja ir y volver entre active y overdue, y reabrir lo pagado', function (): void {
    $roundTrips = [];

    foreach (LayawayStatus::cases() as $a) {
        foreach ($a->allowedTransitions() as $b) {
            if ($b->canTransitionTo($a)) {
                $roundTrips[] = $a->value.' ⇄ '.$b->value;
            }
        }
    }

    expect($roundTrips)->toBe([
        'active ⇄ overdue',
        'active ⇄ completed',
        'overdue ⇄ active',
        'overdue ⇄ completed',
        'completed ⇄ active',
        'completed ⇄ overdue',
    ]);
});

it('responde las tres preguntas por estado', function (LayawayStatus $status, bool $terminal, bool $payments, bool $counts): void {
    expect($status->isTerminal())->toBe($terminal)
        ->and($status->acceptsPayments())->toBe($payments)
        ->and($status->countsTowardPlanLimit())->toBe($counts);
})->with([
    'pending_down_payment' => [LayawayStatus::PendingDownPayment, false, true, true],
    'active' => [LayawayStatus::Active, false, true, true],
    'overdue' => [LayawayStatus::Overdue, false, true, true],
    'completed' => [LayawayStatus::Completed, true, false, false],
    'cancelled' => [LayawayStatus::Cancelled, true, false, false],
    'expired' => [LayawayStatus::Expired, true, false, false],
]);

it('llama terminal exactamente a lo que no tiene salida salvo reabrirse', function (LayawayStatus $status): void {
    $exits = array_filter($status->allowedTransitions(), fn (LayawayStatus $to): bool => ! $status->reopensTo($to));

    expect($status->isTerminal())->toBe($exits === []);
})->with(LayawayStatus::cases());

it('solo reabre un apartado pagado, hacia activo o en mora', function (): void {
    $reopenings = [];

    foreach (LayawayStatus::cases() as $from) {
        foreach (LayawayStatus::cases() as $to) {
            if ($from->reopensTo($to)) {
                $reopenings[] = $from->value.' → '.$to->value;
            }
        }
    }

    expect($reopenings)->toBe(['completed → active', 'completed → overdue']);
});

it('nombra cada estado para el cliente sin repetir ninguno', function (): void {
    $labels = array_map(fn (LayawayStatus $status): string => $status->customerLabel(), LayawayStatus::cases());

    expect(array_unique($labels))->toHaveCount(count(LayawayStatus::cases()))
        ->and(LayawayStatus::PendingDownPayment->customerLabel())->toBe('Esperando la inicial')
        ->and(LayawayStatus::Expired->customerLabel())->toBe('Vencido sin inicial');
});

it('nombra cada estado para el negocio sin repetir ninguno', function (): void {
    $labels = array_map(fn (LayawayStatus $status): string => $status->label(), LayawayStatus::cases());

    expect(array_unique($labels))->toHaveCount(count(LayawayStatus::cases()))
        ->and(LayawayStatus::Overdue->label())->toBe('En mora')
        ->and(LayawayStatus::Overdue->label())->not->toBe(LayawayStatus::Overdue->customerLabel());
});

it('se entrega en cualquier estado vivo o pagado, nunca cancelado ni expirado', function (): void {
    $deliverable = array_map(
        fn (LayawayStatus $status): string => $status->value,
        array_values(array_filter(LayawayStatus::cases(), fn (LayawayStatus $status): bool => $status->allowsDelivery())),
    );

    expect($deliverable)->toBe(['pending_down_payment', 'active', 'overdue', 'completed']);
});
