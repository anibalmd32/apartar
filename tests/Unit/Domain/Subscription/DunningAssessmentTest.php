<?php

declare(strict_types=1);

use App\Domain\Subscription\DunningAssessment;
use App\Domain\Subscription\DunningVerdict;
use App\Domain\Subscription\SubscriptionStatus;
use Carbon\CarbonImmutable;

function assessAt(string $now, SubscriptionStatus $status = SubscriptionStatus::Active, ?string $debtSince = '2026-11-01', bool $underReview = false, ?string $lastReviewedAt = null, bool $billable = true, string $timezone = 'America/Caracas'): DunningAssessment
{
    return DunningAssessment::of(
        status: $status,
        billable: $billable,
        debtSince: $debtSince === null ? null : CarbonImmutable::parse($debtSince, $timezone),
        underReview: $underReview,
        lastReviewedAt: $lastReviewedAt === null ? null : CarbonImmutable::parse($lastReviewedAt, $timezone),
        now: CarbonImmutable::parse($now, $timezone),
        timezone: $timezone,
    );
}

it('pasa a past_due con siete días de gracia desde el vencimiento', function (): void {
    $assessment = assessAt('2026-11-02 10:00');

    expect($assessment->verdict)->toBe(DunningVerdict::PastDue)
        ->and($assessment->graceLastDay?->toDateString())->toBe('2026-11-08')
        ->and($assessment->daysLeft)->toBe(6)
        ->and($assessment->frozen)->toBeFalse()
        ->and($assessment->extended)->toBeFalse();
});

it('guarda como fin de la gracia el final del último día en la zona del negocio', function (): void {
    $assessment = assessAt('2026-11-02 10:00');

    expect($assessment->graceEndsAt?->toIso8601String())->toBe('2026-11-08T23:59:59-04:00');
});

it('sigue en la gracia el último día y suspende el siguiente', function (string $now, DunningVerdict $verdict, int $daysLeft): void {
    $assessment = assessAt($now);

    expect($assessment->verdict)->toBe($verdict)->and($assessment->daysLeft)->toBe($daysLeft);
})->with([
    'el último día, por la noche' => ['2026-11-08 23:59', DunningVerdict::PastDue, 0],
    'el día siguiente, de madrugada' => ['2026-11-09 00:01', DunningVerdict::Suspended, -1],
    'un mes después' => ['2026-12-09 12:00', DunningVerdict::Suspended, -31],
]);

it('cuenta los días en el calendario del negocio, no en el de la plataforma', function (): void {
    $instant = CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC');

    $caracas = DunningAssessment::of(SubscriptionStatus::PastDue, true, CarbonImmutable::parse('2026-11-01', 'America/Caracas'), false, null, $instant, 'America/Caracas');
    $kiritimati = DunningAssessment::of(SubscriptionStatus::PastDue, true, CarbonImmutable::parse('2026-11-01', 'Pacific/Kiritimati'), false, null, $instant, 'Pacific/Kiritimati');

    expect($caracas->verdict)->toBe(DunningVerdict::PastDue)
        ->and($kiritimati->verdict)->toBe(DunningVerdict::Suspended);
});

it('un comprobante en revisión congela la gracia agotada', function (): void {
    $assessment = assessAt('2026-11-20 10:00', SubscriptionStatus::PastDue, underReview: true);

    expect($assessment->verdict)->toBe(DunningVerdict::PastDue)->and($assessment->frozen)->toBeTrue();
});

it('una revisión que dejó la deuda en pie da 24 h desde ella', function (): void {
    $rejected = '2026-11-12 09:00';

    expect(assessAt('2026-11-12 20:00', SubscriptionStatus::PastDue, lastReviewedAt: $rejected)->verdict)->toBe(DunningVerdict::PastDue)
        ->and(assessAt('2026-11-12 20:00', SubscriptionStatus::PastDue, lastReviewedAt: $rejected)->extended)->toBeTrue()
        ->and(assessAt('2026-11-13 08:59', SubscriptionStatus::PastDue, lastReviewedAt: $rejected)->verdict)->toBe(DunningVerdict::PastDue)
        ->and(assessAt('2026-11-13 09:00', SubscriptionStatus::PastDue, lastReviewedAt: $rejected)->verdict)->toBe(DunningVerdict::Suspended);
});

it('una revisión vieja no prorroga nada', function (): void {
    expect(assessAt('2026-11-10 10:00', SubscriptionStatus::PastDue, lastReviewedAt: '2026-11-03 10:00')->verdict)->toBe(DunningVerdict::Suspended);
});

it('una suspendida sigue suspendida aunque llegue un comprobante o una prórroga: solo pagar la levanta', function (): void {
    expect(assessAt('2026-11-20 10:00', SubscriptionStatus::Suspended, underReview: true)->verdict)->toBe(DunningVerdict::Suspended)
        ->and(assessAt('2026-11-20 10:00', SubscriptionStatus::Suspended, lastReviewedAt: '2026-11-20 09:00')->verdict)->toBe(DunningVerdict::Suspended);
});

it('llega al mismo estado sin haber pasado por los anteriores', function (): void {
    expect(assessAt('2026-11-15 10:00', SubscriptionStatus::Active)->verdict)->toBe(DunningVerdict::Suspended);
});

it('sin facturas vencidas está al día, también si estaba suspendida', function (SubscriptionStatus $status): void {
    $assessment = assessAt('2026-11-20 10:00', $status, debtSince: null);

    expect($assessment->verdict)->toBe(DunningVerdict::Settled)
        ->and($assessment->graceLastDay)->toBeNull()
        ->and($assessment->graceEndsAt)->toBeNull();
})->with([SubscriptionStatus::Active, SubscriptionStatus::Grace, SubscriptionStatus::PastDue, SubscriptionStatus::Suspended]);

it('un plan sin precio no se persigue aunque quede una factura vencida', function (): void {
    expect(assessAt('2026-11-20 10:00', SubscriptionStatus::Suspended, billable: false)->verdict)->toBe(DunningVerdict::Settled);
});

it('no toca una suscripción dada de baja', function (): void {
    expect(assessAt('2026-11-20 10:00', SubscriptionStatus::Cancelled)->verdict)->toBe(DunningVerdict::Untouched);
});

it('admite otra cantidad de días de gracia', function (): void {
    $assessment = DunningAssessment::of(SubscriptionStatus::PastDue, true, CarbonImmutable::parse('2026-11-01', 'America/Caracas'), false, null, CarbonImmutable::parse('2026-11-04 10:00', 'America/Caracas'), 'America/Caracas', graceDays: 2);

    expect($assessment->graceLastDay?->toDateString())->toBe('2026-11-03')->and($assessment->verdict)->toBe(DunningVerdict::Suspended);
});

it('la deuda empieza el vencimiento, o el día de emisión si la factura se emitió ya vencida', function (): void {
    $due = CarbonImmutable::parse('2026-11-01');

    expect(DunningAssessment::debtStartOf($due, CarbonImmutable::parse('2026-10-25 10:00', 'America/Caracas'), 'America/Caracas')->toDateString())->toBe('2026-11-01')
        ->and(DunningAssessment::debtStartOf($due, CarbonImmutable::parse('2026-12-15 10:00', 'America/Caracas'), 'America/Caracas')->toDateString())->toBe('2026-12-15');
});

it('lee el día de emisión en la zona del negocio', function (): void {
    $issued = CarbonImmutable::parse('2026-12-14 23:00', 'America/Caracas');

    expect(DunningAssessment::debtStartOf(CarbonImmutable::parse('2026-11-01'), $issued, 'Pacific/Kiritimati')->toDateString())->toBe('2026-12-15')
        ->and(DunningAssessment::debtStartOf(CarbonImmutable::parse('2026-11-01'), $issued, 'America/Caracas')->toDateString())->toBe('2026-12-14');
});

it('usa los días de gracia que le pasan', function (): void {
    expect(DunningAssessment::GRACE_DAYS)->toBe(7);
});
