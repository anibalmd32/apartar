<?php

declare(strict_types=1);

use App\Domain\Mail\MailCategory;
use App\Notifications\TeamInvitationNotification;

it('toma la categoría de la notificación o del mailable que envía', function (array $data, string $expected): void {
    expect(MailCategory::fromMessageData($data))->toBe($expected);
})->with([
    'notificación' => [['__laravel_notification' => TeamInvitationNotification::class], 'TeamInvitationNotification'],
    'del framework' => [['__laravel_notification' => 'Illuminate\\Auth\\Notifications\\ResetPassword'], 'ResetPassword'],
    'mailable' => [['__laravel_mailable' => 'App\\Mail\\OwnerMail'], MailCategory::MANUAL],
    'sin datos' => [[], MailCategory::UNKNOWN],
    'vacío' => [['__laravel_notification' => ''], MailCategory::UNKNOWN],
]);

it('nombra en español las categorías conocidas', function (): void {
    expect(MailCategory::label('TeamInvitationNotification'))->toBe('Invitación al equipo')
        ->and(MailCategory::label(MailCategory::MANUAL))->toBe('Escrito por el equipo')
        ->and(MailCategory::label('Inventada'))->toBe('Inventada');
});

it('limita el largo de la categoría', function (): void {
    expect(mb_strlen(MailCategory::fromClass('App\\'.str_repeat('X', 300))))->toBe(MailCategory::MAX_LENGTH);
});
