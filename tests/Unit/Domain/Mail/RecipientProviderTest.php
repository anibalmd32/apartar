<?php

declare(strict_types=1);

use App\Domain\Mail\RecipientProvider;

it('saca el dominio de una dirección', function (string $address, string $domain): void {
    expect(RecipientProvider::domainOf($address))->toBe($domain);
})->with([
    ['ana@Gmail.com', 'gmail.com'],
    ['Ana <ana@hotmail.com>', 'hotmail.com'],
    ['sin-arroba', ''],
    ['', ''],
]);

it('agrupa los dominios en su proveedor y deja el resto con su dominio', function (string $domain, string $provider): void {
    expect(RecipientProvider::of($domain))->toBe($provider);
})->with([
    ['gmail.com', 'Gmail'],
    ['googlemail.com', 'Gmail'],
    ['hotmail.com', 'Outlook'],
    ['outlook.es', 'Outlook'],
    ['yahoo.com.ve', 'Yahoo'],
    ['icloud.com', 'iCloud'],
    ['empresa.com.ve', 'empresa.com.ve'],
    ['', RecipientProvider::OTHER],
]);
