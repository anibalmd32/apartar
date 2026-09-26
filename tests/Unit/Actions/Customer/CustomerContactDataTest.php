<?php

declare(strict_types=1);

use App\Actions\Customer\CustomerContactData;
use App\Support\DocumentKind;
use App\Support\InvalidDocumentNumberException;
use App\Support\InvalidPhoneNumberException;

/**
 * El contacto que declara el cliente (`REQ-IDN-001`), antes de llegar a la
 * acción.
 */
it('normaliza documento, teléfono y nombre al construirse desde la entrada', function (): void {
    $contact = CustomerContactData::fromInput('12.345.678', "  María   González\t", '0414-123.45.67', ' maria@ejemplo.test ');

    expect($contact->documentNumber->value)->toBe('V12345678');
    expect($contact->documentNumber->kind)->toBe(DocumentKind::Cedula);
    expect($contact->phone->value)->toBe('+584141234567');
    expect($contact->fullName)->toBe('María González');
    expect($contact->email)->toBe('maria@ejemplo.test');
});

it('acepta un RIF (RB-7)', function (): void {
    expect(CustomerContactData::fromInput('J-12345678-9', 'Inversiones El Tornillo C.A.', '0212-5551234', null)->documentNumber->kind)
        ->toBe(DocumentKind::Rif);
});

/**
 * SP-7 · el campo opcional que se deja en blanco llega como `''`, y eso es
 * «sin correo», no un correo vacío.
 */
it('trata el correo en blanco como ausente', function (?string $email): void {
    expect(CustomerContactData::fromInput('V12345678', 'María González', '04141234567', $email)->email)->toBeNull();
})->with(['nulo' => [null], 'vacío' => [''], 'solo espacios' => ['   ']]);

it('usa las puertas que lanzan para documento y teléfono (SP-1, SP-2)', function (string $document, string $phone, string $exception): void {
    expect(fn () => CustomerContactData::fromInput($document, 'María González', $phone, null))->toThrow($exception);
})->with([
    'documento inválido' => ['X-123', '04141234567', InvalidDocumentNumberException::class],
    'teléfono vacío' => ['V12345678', '', InvalidPhoneNumberException::class],
    'teléfono inválido' => ['V12345678', '12345', InvalidPhoneNumberException::class],
]);

it('rechaza un nombre vacío, uno que no cabe y un correo sin forma de correo', function (string $name, ?string $email): void {
    CustomerContactData::fromInput('V12345678', $name, '04141234567', $email);
})->with([
    'nombre vacío' => ['   ', null],
    'nombre de más de 200' => [str_repeat('a', 201), null],
    'correo sin arroba' => ['María González', 'maria.ejemplo.test'],
])->throws(InvalidArgumentException::class);
