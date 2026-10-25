<?php

declare(strict_types=1);

use App\Domain\Layaway\OverdueSummary;

it('cuenta lo que entra en mora y lo que sale de ella por separado', function (): void {
    $summary = (new OverdueSummary)
        ->withInstallmentMarkedOverdue()
        ->withInstallmentMarkedOverdue()
        ->withInstallmentReturned()
        ->withLayawayBecameOverdue()
        ->withLayawayCaughtUp();

    expect($summary->toArray())->toBe([
        'installments_marked_overdue' => 2,
        'installments_returned' => 1,
        'layaways_became_overdue' => 1,
        'layaways_caught_up' => 1,
    ]);
});

it('suma las pasadas de dos organizaciones sin tocar ninguna', function (): void {
    $first = new OverdueSummary(installmentsMarkedOverdue: 3, layawaysBecameOverdue: 1);
    $second = new OverdueSummary(installmentsReturned: 2, layawaysCaughtUp: 1);

    expect($first->plus($second)->toArray())->toBe([
        'installments_marked_overdue' => 3,
        'installments_returned' => 2,
        'layaways_became_overdue' => 1,
        'layaways_caught_up' => 1,
    ])->and($first->installmentsReturned)->toBe(0);
});
