<?php

declare(strict_types=1);

use App\Support\DocumentKind;
use App\Support\DocumentNumber;
use App\Support\InvalidDocumentNumberException;

dataset('escrituras de V12345678', [
    'canónica' => ['V12345678'],
    'minúscula' => ['v12345678'],
    'con guion' => ['V-12345678'],
    'con guion y puntos' => ['V-12.345.678'],
    'minúscula con guion y puntos' => ['v-12.345.678'],
    'sin letra' => ['12345678'],
    'sin letra y con puntos' => ['12.345.678'],
    'con espacios' => ['V 12 345 678'],
    'con espacios alrededor' => ['  V-12.345.678  '],
    'con guion y espacios sueltos' => ['V - 12 . 345 . 678'],
    'con espacio duro' => ["V\u{00A0}12345678"],
    'con raya tipográfica' => ['V–12.345.678'],
]);

it('reduce cada escritura de la cédula a la misma forma canónica', function (string $raw): void {
    $document = DocumentNumber::fromInput($raw);

    expect($document->value)->toBe('V12345678')
        ->and($document->kind)->toBe(DocumentKind::Cedula);
})->with('escrituras de V12345678');

it('da la misma clave a 12.345.678 y a V12345678', function (): void {
    $typed = DocumentNumber::tryFromInput('12.345.678');
    $stored = DocumentNumber::tryFromInput('V12345678');

    expect($typed)->not->toBeNull()
        ->and($typed?->value)->toBe($stored?->value)
        ->and($typed?->equals(new DocumentNumber('V12345678')))->toBeTrue();
});

it('acepta la cédula de extranjero con su E', function (): void {
    $document = DocumentNumber::fromInput('e-84.123.456');

    expect($document->value)->toBe('E84123456')
        ->and($document->kind)->toBe(DocumentKind::Cedula);
});

it('conserva los ceros a la izquierda y cuenta los dígitos sobre la cadena', function (): void {
    $withZeros = DocumentNumber::fromInput('V-0012345');

    expect($withZeros->value)->toBe('V0012345')
        ->and($withZeros->digits())->toBe('0012345')
        ->and($withZeros->formatted())->toBe('V-0.012.345');

    expect(DocumentNumber::fromInput('V-00123456')->equals(DocumentNumber::fromInput('V-123456')))->toBeFalse();

    expect(DocumentNumber::fromInput('0012345')->value)->toBe('V0012345');
});

it('lee el RIF con su letra', function (string $raw, string $canonical): void {
    $document = DocumentNumber::fromInput($raw);

    expect($document->value)->toBe($canonical)
        ->and($document->kind)->toBe(DocumentKind::Rif);
})->with([
    ['J-12345678-9', 'J123456789'],
    ['j123456789', 'J123456789'],
    ['G-20000123-4', 'G200001234'],
    ['J-12345678', 'J12345678'],
]);

it('lee como cédula un RIF escrito sin su letra', function (): void {
    $document = DocumentNumber::fromInput('12345678-9');

    expect($document->value)->toBe('V123456789')
        ->and($document->kind)->toBe(DocumentKind::Cedula);
});

it('no valida el dígito verificador del RIF', function (): void {
    expect(DocumentNumber::fromInput('J-12345678-0')->value)->toBe('J123456780')
        ->and(DocumentNumber::fromInput('J-12345678-9')->value)->toBe('J123456789');
});

it('enseña la cédula con puntos y el RIF con su verificador aparte', function (string $canonical, string $formatted): void {
    expect((new DocumentNumber($canonical))->formatted())->toBe($formatted);
})->with([
    ['V12345678', 'V-12.345.678'],
    ['V123456', 'V-123.456'],
    ['E123456789', 'E-123.456.789'],
    ['J123456789', 'J-12345678-9'],
    ['J12345678', 'J-12345678'],
    ['J1234567890', 'J-123456789-0'],
]);

it('rechaza una cédula fuera de rango diciendo el rango', function (): void {
    expect(fn () => DocumentNumber::fromInput('V-12.345'))
        ->toThrow(InvalidDocumentNumberException::class, 'Una cédula tiene entre 6 y 9 dígitos, y aquí hay 5.');
});

it('sugiere la letra del RIF a una cédula de dígitos de más', function (): void {
    expect(fn () => DocumentNumber::fromInput('1234567890'))
        ->toThrow(InvalidDocumentNumberException::class, 'Si es el RIF de una empresa, escríbelo con su letra delante (J o G)');
});

it('rechaza un RIF fuera de rango diciendo el rango', function (): void {
    expect(fn () => DocumentNumber::fromInput('J-1234567'))
        ->toThrow(InvalidDocumentNumberException::class, 'Un RIF tiene entre 8 y 10 dígitos contando el verificador del final, y aquí hay 7.');
});

it('rechaza una letra desconocida diciendo cuáles valen', function (): void {
    expect(fn () => DocumentNumber::fromInput('P-123'))
        ->toThrow(InvalidDocumentNumberException::class, 'Un documento empieza por V o E si es una cédula, o por J o G si es un RIF, y "P" no es ninguna de ellas.');
});

it('rechaza lo que no es letra ni dígito', function (string $raw): void {
    expect(fn () => DocumentNumber::fromInput($raw))
        ->toThrow(InvalidDocumentNumberException::class, 'Escribe solo la letra y los números del documento');
})->with(['V-12,345,678', 'VJ12345678', '12345678V', '#12345678', 'Ñ12345678']);

it('rechaza el documento vacío', function (string $raw): void {
    expect(fn () => DocumentNumber::fromInput($raw))
        ->toThrow(InvalidDocumentNumberException::class, 'Escribe el número de tu cédula o RIF.');
})->with(['', '   ', ' - . ']);

it('lanza en el constructor si lo que recibe no es la forma canónica', function (string $value): void {
    expect(fn () => new DocumentNumber($value))->toThrow(InvalidDocumentNumberException::class);
})->with(['v12345678', 'V-12345678', '12345678', 'V12345', '']);

it('devuelve null en la puerta de preguntar cuando la otra lanzaría', function (mixed $raw): void {
    expect(DocumentNumber::tryFromInput($raw))->toBeNull();
})->with(['12.34', 'P-123', 'María', '', null, 12345678, [['V12345678']]]);

it('devuelve el documento en la puerta de preguntar cuando lo es', function (): void {
    expect(DocumentNumber::tryFromInput('v-12.345.678')?->value)->toBe('V12345678');
});

it('acepta 12.345.6 por las dos puertas, porque seis dígitos son una cédula', function (): void {
    expect(DocumentNumber::fromInput('12.345.6')->value)->toBe('V123456')
        ->and(DocumentNumber::tryFromInput('12.345.6')?->value)->toBe('V123456');
});

it('declara el censo de letras y rangos en el tipo', function (): void {
    expect(DocumentKind::fromPrefix('V'))->toBe(DocumentKind::Cedula)
        ->and(DocumentKind::fromPrefix('E'))->toBe(DocumentKind::Cedula)
        ->and(DocumentKind::fromPrefix('J'))->toBe(DocumentKind::Rif)
        ->and(DocumentKind::fromPrefix('G'))->toBe(DocumentKind::Rif)
        ->and(DocumentKind::fromPrefix('P'))->toBeNull()
        ->and(DocumentKind::Cedula->label())->toBe('cédula')
        ->and(DocumentKind::Rif->label())->toBe('RIF')
        ->and(DocumentKind::Cedula->value)->toBe('cedula')
        ->and(DocumentKind::Rif->value)->toBe('rif');
});

it('tapa el medio del documento', function (): void {
    expect(DocumentNumber::fromInput('V-18.234.567')->masked())->toBe('V-18.***.567')
        ->and(DocumentNumber::fromInput('V-1.234.567')->masked())->toBe('V-1.***.567')
        ->and(DocumentNumber::fromInput('J-50123456-7')->masked())->not->toContain('0123');
});
