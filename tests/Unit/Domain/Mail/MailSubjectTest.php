<?php

declare(strict_types=1);

use App\Domain\Mail\MailSubject;

it('antepone Re: sin repetirlo', function (string $subject, string $expected): void {
    expect(MailSubject::reply($subject))->toBe($expected);
})->with([
    ['Pregunta por el plan', 'Re: Pregunta por el plan'],
    ['Re: Pregunta', 'Re: Pregunta'],
    ['RE: Pregunta', 'RE: Pregunta'],
    ['', 'Re: (sin asunto)'],
]);

it('antepone Rv: al reenviar', function (): void {
    expect(MailSubject::forward('Factura'))->toBe('Rv: Factura')
        ->and(MailSubject::forward('Rv: Factura'))->toBe('Rv: Factura');
});

it('no pasa del largo máximo', function (): void {
    expect(mb_strlen(MailSubject::reply(str_repeat('a', 400))))->toBe(MailSubject::MAX_LENGTH);
});
