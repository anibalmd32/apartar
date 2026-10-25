<?php

declare(strict_types=1);

use App\Support\DocumentKind;
use App\Support\DocumentNumber;
use App\Support\PhoneNumber;

/**
 * @return array{documents: list<array{input: string, formatted: string}>, phones: list<array{input: string, formatted: string}>, phoneAreaCodes: list<array{input: string, areaCode: string}>}
 */
function identityFormatCases(): array
{
    $path = dirname(__DIR__, 3).'/resources/js/lib/identity-format.cases.json';

    /** @var array{documents: list<array{input: string, formatted: string}>, phones: list<array{input: string, formatted: string}>, phoneAreaCodes: list<array{input: string, areaCode: string}>} */
    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

it('enseña cada documento de la tabla igual que la máscara', function (): void {
    foreach (identityFormatCases()['documents'] as $case) {
        expect(DocumentNumber::fromInput($case['input'])->formatted())
            ->toBe($case['formatted'], "La presentación de {$case['input']} no coincide con la tabla compartida.");
    }
});

it('enseña cada teléfono de la tabla igual que la máscara', function (): void {
    foreach (identityFormatCases()['phones'] as $case) {
        expect(PhoneNumber::fromInput($case['input'])->formatted())
            ->toBe($case['formatted'], "La presentación de {$case['input']} no coincide con la tabla compartida.");
    }
});

it('lee el código de cada teléfono de la tabla igual que la máscara', function (): void {
    foreach (identityFormatCases()['phoneAreaCodes'] as $case) {
        expect(PhoneNumber::fromInput($case['input'])->areaCode())
            ->toBe($case['areaCode'], "El código de {$case['input']} no coincide con la tabla compartida.");
    }
});

it('cubre en la tabla cada letra de cada tipo de documento', function (): void {
    $covered = array_map(
        fn (array $case): string => DocumentNumber::fromInput($case['input'])->prefix(),
        identityFormatCases()['documents'],
    );

    foreach (DocumentKind::cases() as $kind) {
        foreach ($kind->prefixes() as $letter) {
            expect($covered)->toContain($letter);
        }
    }
});
