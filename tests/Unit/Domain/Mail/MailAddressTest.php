<?php

declare(strict_types=1);

use App\Domain\Mail\MailAddress;

it('separa el nombre y el correo', function (mixed $value, ?string $email, ?string $name): void {
    $address = MailAddress::parse($value);

    expect($address?->email)->toBe($email)
        ->and($address?->name)->toBe($name);
})->with([
    ['Ivan Djuric <Demo.Eth@gmail.com>', 'demo.eth@gmail.com', 'Ivan Djuric'],
    ['"Rosa Pérez" <rosa@example.com>', 'rosa@example.com', 'Rosa Pérez'],
    ['<rosa@example.com>', 'rosa@example.com', null],
    ['rosa@example.com', 'rosa@example.com', null],
    ['no es un correo', null, null],
    ['', null, null],
    [null, null, null],
]);

it('reúne una lista de correos sin repetir ni aceptar basura', function (): void {
    expect(MailAddress::emails('ana@example.com, Luis <luis@example.com>; ana@example.com, basura'))->toBe(['ana@example.com', 'luis@example.com'])
        ->and(MailAddress::emails(['ana@example.com', 'x']))->toBe(['ana@example.com'])
        ->and(MailAddress::emails(null))->toBe([]);
});

it('se muestra con nombre si lo tiene', function (): void {
    expect((new MailAddress('rosa@example.com', 'Rosa'))->display())->toBe('Rosa <rosa@example.com>')
        ->and((new MailAddress('rosa@example.com'))->display())->toBe('rosa@example.com');
});
