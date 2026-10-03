<?php

declare(strict_types=1);

use App\Domain\Layaway\LayawayStatus;

/**
 * Los seis estados y sus cuatro preguntas (`REQ-LAY-005` RB-1, RB-3, RB-6,
 * RB-8). La matriz se prueba aquí sobre el enum, y otra vez en
 * `LayawayStateMachineTest` a través de la máquina y contra la base.
 */

/**
 * La matriz de RB-1, **literal**: nueve celdas válidas de treinta y seis.
 *
 * @return array<string, array{0: LayawayStatus, 1: LayawayStatus, 2: bool}>
 */
function transitionMatrix(): array
{
    $valid = [
        'pending_down_payment' => ['active', 'expired', 'cancelled'],
        'active' => ['overdue', 'completed', 'cancelled'],
        'overdue' => ['active', 'completed', 'cancelled'],
        'completed' => [],
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

it('admite exactamente las transiciones de RB-1', function (LayawayStatus $from, LayawayStatus $to, bool $valid): void {
    expect($from->canTransitionTo($to))->toBe($valid)
        ->and(in_array($to, $from->allowedTransitions(), true))->toBe($valid);
})->with(transitionMatrix());

it('tiene nueve celdas válidas de treinta y seis', function (): void {
    expect(array_filter(transitionMatrix(), fn (array $cell): bool => $cell[2]))->toHaveCount(9)
        ->and(transitionMatrix())->toHaveCount(36);
});

/**
 * RB-4: `overdue` ⇄ `active` es la única pareja que va y vuelve.
 */
it('solo deja ir y volver entre active y overdue', function (): void {
    $roundTrips = [];

    foreach (LayawayStatus::cases() as $a) {
        foreach ($a->allowedTransitions() as $b) {
            if ($b->canTransitionTo($a)) {
                $roundTrips[] = $a->value.' ⇄ '.$b->value;
            }
        }
    }

    expect($roundTrips)->toBe(['active ⇄ overdue', 'overdue ⇄ active']);
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

/**
 * RB-8: un terminal no va a ninguna parte, y lo que no es terminal va a algún
 * sitio. Las dos definiciones no pueden discrepar.
 */
it('llama terminal exactamente a lo que no tiene salida', function (LayawayStatus $status): void {
    expect($status->isTerminal())->toBe($status->allowedTransitions() === []);
})->with(LayawayStatus::cases());

/**
 * `T-049` · cada estado tiene su nombre para el cliente, distinto de los
 * demás: la lista vive en el enum, no en el `.tsx`.
 */
it('nombra cada estado para el cliente sin repetir ninguno', function (): void {
    $labels = array_map(fn (LayawayStatus $status): string => $status->customerLabel(), LayawayStatus::cases());

    expect(array_unique($labels))->toHaveCount(count(LayawayStatus::cases()))
        ->and(LayawayStatus::PendingDownPayment->customerLabel())->toBe('Esperando la inicial')
        ->and(LayawayStatus::Expired->customerLabel())->toBe('Vencido sin inicial');
});

/**
 * `T-053`: el backoffice nombra los estados con palabras del negocio —«en
 * mora», «expirado»—, distintas de las del cliente.
 */
it('nombra cada estado para el negocio sin repetir ninguno', function (): void {
    $labels = array_map(fn (LayawayStatus $status): string => $status->label(), LayawayStatus::cases());

    expect(array_unique($labels))->toHaveCount(count(LayawayStatus::cases()))
        ->and(LayawayStatus::Overdue->label())->toBe('En mora')
        ->and(LayawayStatus::Overdue->label())->not->toBe(LayawayStatus::Overdue->customerLabel());
});
