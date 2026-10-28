<?php

declare(strict_types=1);

use App\Domain\Mail\MailStatus;
use Carbon\CarbonImmutable;

it('avanza al estado de más peso y no retrocede', function (MailStatus $current, MailStatus $next, MailStatus $expected): void {
    expect($current->advancedBy($next))->toBe($expected);
})->with([
    'entrega tras envío' => [MailStatus::Sent, MailStatus::Delivered, MailStatus::Delivered],
    'apertura tras entrega' => [MailStatus::Delivered, MailStatus::Opened, MailStatus::Opened],
    'entrega tardía tras apertura' => [MailStatus::Opened, MailStatus::Delivered, MailStatus::Opened],
    'clic tras apertura' => [MailStatus::Opened, MailStatus::Clicked, MailStatus::Clicked],
    'spam tras clic' => [MailStatus::Clicked, MailStatus::Spam, MailStatus::Spam],
    'entrega tras rebote temporal' => [MailStatus::SoftBounced, MailStatus::Delivered, MailStatus::Delivered],
    'rebote tras rebote temporal' => [MailStatus::SoftBounced, MailStatus::Bounced, MailStatus::Bounced],
    'apertura no tapa el rebote' => [MailStatus::Bounced, MailStatus::Opened, MailStatus::Bounced],
]);

it('da por fallido un envío que sigue pendiente pasado el plazo', function (): void {
    $now = CarbonImmutable::parse('2026-10-28 12:00');

    expect(MailStatus::Sending->effective($now->subMinutes(MailStatus::PENDING_TIMEOUT_MINUTES + 1), $now))->toBe(MailStatus::Failed)
        ->and(MailStatus::Sending->effective($now->subMinutes(1), $now))->toBe(MailStatus::Sending)
        ->and(MailStatus::Sent->effective($now->subDays(3), $now))->toBe(MailStatus::Sent)
        ->and(MailStatus::Sending->effective(null, $now))->toBe(MailStatus::Sending);
});

it('nombra cada estado en español y marca los problemáticos', function (): void {
    expect(MailStatus::labels())->toHaveCount(count(MailStatus::cases()))
        ->and(MailStatus::Delivered->label())->toBe('Entregado')
        ->and(MailStatus::Bounced->isProblem())->toBeTrue()
        ->and(MailStatus::Opened->isProblem())->toBeFalse();
});
