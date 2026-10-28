<?php

declare(strict_types=1);

use App\Domain\Mail\MailtrapSignature;

const SIGNING_SECRET = '0123456789abcdef0123456789abcdef';

it('firma con HMAC-SHA256 en hexadecimal sobre el cuerpo crudo', function (): void {
    $body = '{"events":[{"event":"delivery"}]}';

    expect(MailtrapSignature::sign($body, SIGNING_SECRET))->toBe(hash_hmac('sha256', $body, SIGNING_SECRET))
        ->toMatch('/^[0-9a-f]{64}$/');
});

it('acepta la firma correcta aunque llegue en mayúsculas', function (): void {
    $body = '{"events":[]}';
    $signature = MailtrapSignature::sign($body, SIGNING_SECRET);

    expect(MailtrapSignature::verify($body, $signature, SIGNING_SECRET))->toBeTrue()
        ->and(MailtrapSignature::verify($body, strtoupper($signature), SIGNING_SECRET))->toBeTrue();
});

it('rechaza una firma ajena, ausente o sin secreto configurado', function (?string $signature, ?string $secret): void {
    expect(MailtrapSignature::verify('{"events":[]}', $signature, $secret))->toBeFalse();
})->with([
    'otra firma' => [str_repeat('a', 64), SIGNING_SECRET],
    'firmado con otro secreto' => [hash_hmac('sha256', '{"events":[]}', 'otro'), SIGNING_SECRET],
    'sin cabecera' => [null, SIGNING_SECRET],
    'cabecera vacía' => ['', SIGNING_SECRET],
    'sin secreto' => [hash_hmac('sha256', '{"events":[]}', ''), null],
    'secreto vacío' => [hash_hmac('sha256', '{"events":[]}', ''), ''],
]);

it('rechaza un cuerpo alterado', function (): void {
    $signature = MailtrapSignature::sign('{"events":[]}', SIGNING_SECRET);

    expect(MailtrapSignature::verify('{"events":[{}]}', $signature, SIGNING_SECRET))->toBeFalse()
        ->and(MailtrapSignature::verify('', $signature, SIGNING_SECRET))->toBeFalse();
});
