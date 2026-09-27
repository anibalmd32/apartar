<?php

declare(strict_types=1);

use App\Support\DocumentKind;
use App\Support\DocumentNumber;
use App\Support\PhoneNumber;

/**
 * La mitad de PHP de la tabla que comparte con la máscara del frontend
 * (`REQ-IDN-007`, decisión escrita bajo el requisito en [10]).
 *
 * `identity-format.ts` conserva un censo —qué letras son de un RIF— porque la
 * cédula y el RIF se escriben distinto mientras se teclean. No se puede
 * compartir el código entre los dos lenguajes, así que se comparte la tabla:
 * `identity-format.test.ts` la recorre contra la máscara y este archivo contra
 * `formatted()`. Si los dos lados enseñan distinto una escritura completa, uno
 * de los dos se pone rojo.
 *
 * Y la tabla no se puede quedar corta en silencio: **cada letra de
 * `DocumentKind` tiene que aparecer** en algún caso. Si el tipo gana una
 * letra, esto falla hasta que la tabla la lleve, y entonces es la máscara la
 * que falla hasta que la aprenda.
 *
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

/**
 * `T-045b` · el aviso de prefijo del formulario lee el código en el navegador
 * con `phoneAreaCode()`, y tiene que leer el mismo que `PhoneNumber`.
 */
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
