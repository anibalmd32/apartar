<?php

declare(strict_types=1);

use App\Domain\Mail\ComposeMode;
use App\Models\InboundMail;

/**
 * @param  list<string>  $to
 * @param  list<string>  $cc
 */
function inboundFrom(string $from, array $to, array $cc = [], ?string $replyTo = null): InboundMail
{
    $mail = new InboundMail;
    $mail->from_address = $from;
    $mail->to = $to;
    $mail->cc = $cc;
    $mail->reply_to = $replyTo;

    return $mail;
}

it('responde solo a quien escribió, o a su Reply-To', function (): void {
    expect(ComposeMode::Reply->recipients(inboundFrom('ana@gmail.com', ['info@apartar.app'], ['luis@example.com']), 'apartar.app'))
        ->toBe(['to' => ['ana@gmail.com'], 'cc' => []])
        ->and(ComposeMode::Reply->recipients(inboundFrom('ana@gmail.com', ['info@apartar.app'], [], 'Ana@Empresa.com'), 'apartar.app'))
        ->toBe(['to' => ['ana@empresa.com'], 'cc' => []]);
});

it('responde a todos sin incluir nuestras direcciones ni repetir al remitente', function (): void {
    $source = inboundFrom('ana@gmail.com', ['info@apartar.app', 'luis@example.com', 'ventas@APARTAR.app'], ['ana@gmail.com', 'rosa@example.com', 'luis@example.com']);

    expect(ComposeMode::ReplyAll->recipients($source, 'apartar.app'))
        ->toBe(['to' => ['ana@gmail.com'], 'cc' => ['luis@example.com', 'rosa@example.com']]);
});

it('reenvía sin destinatarios propuestos', function (): void {
    expect(ComposeMode::Forward->recipients(inboundFrom('ana@gmail.com', ['info@apartar.app']), 'apartar.app'))->toBe(['to' => [], 'cc' => []]);
});

it('ajusta el asunto según el modo', function (): void {
    expect(ComposeMode::New->subject('Hola'))->toBe('Hola')
        ->and(ComposeMode::Reply->subject('Hola'))->toBe('Re: Hola')
        ->and(ComposeMode::ReplyAll->subject('Re: Hola'))->toBe('Re: Hola')
        ->and(ComposeMode::Forward->subject('Hola'))->toBe('Rv: Hola');
});

it('sabe cuándo necesita un correo de origen y cuándo hila con él', function (): void {
    expect(ComposeMode::New->needsSource())->toBeFalse()
        ->and(ComposeMode::Forward->needsSource())->toBeTrue()
        ->and(ComposeMode::Forward->threadsWithSource())->toBeFalse()
        ->and(ComposeMode::ReplyAll->threadsWithSource())->toBeTrue()
        ->and(ComposeMode::ReplyAll->label())->toBe('Responder a todos');
});
