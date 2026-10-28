<?php

declare(strict_types=1);

use App\Domain\Mail\MailEventType;
use App\Domain\Mail\MailStatus;

it('reconoce los nombres de evento de Mailtrap, con espacio o guion bajo', function (mixed $name, ?MailEventType $expected): void {
    expect(MailEventType::fromMailtrap($name))->toBe($expected);
})->with([
    ['delivery', MailEventType::Delivery],
    ['open', MailEventType::Open],
    ['click', MailEventType::Click],
    ['unsubscribe', MailEventType::Unsubscribe],
    ['spam', MailEventType::Spam],
    ['spam_complaint', MailEventType::Spam],
    ['soft bounce', MailEventType::SoftBounce],
    ['soft_bounce', MailEventType::SoftBounce],
    ['Bounce', MailEventType::Bounce],
    ['suspension', MailEventType::Suspension],
    ['reject', MailEventType::Reject],
    ['activity_log.user.login', null],
    ['', null],
    [42, null],
]);

it('lleva cada evento a su estado', function (): void {
    expect(MailEventType::Delivery->status())->toBe(MailStatus::Delivered)
        ->and(MailEventType::SoftBounce->status())->toBe(MailStatus::SoftBounced)
        ->and(MailEventType::Reject->status())->toBe(MailStatus::Rejected)
        ->and(MailEventType::Suspension->status())->toBe(MailStatus::Suspended)
        ->and(MailEventType::Click->label())->toBe('Clic');
});
