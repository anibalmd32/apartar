<?php

declare(strict_types=1);

use App\Support\FullTextTerm;

/**
 * El saneado del término de búsqueda (`REQ-CAT-002` RB-2, RB-7, SP-1, SP-3,
 * SP-5).
 *
 * Lo que sale de aquí va a un `MATCH … AGAINST` en modo booleano o a un
 * `LIKE`; lo que se prueba es que en ningún caso lleva sintaxis que no haya
 * puesto la propia clase.
 */
it('busca cada palabra como obligatoria y como prefijo', function (): void {
    $term = FullTextTerm::sanitize('Taladro percutor');

    expect($term?->booleanQuery())->toBe('+Taladro* +percutor*')
        ->and($term?->likePatterns())->toBe(['%Taladro%', '%percutor%']);
});

/**
 * RB-7 · el guion de «media-pulgada» separa palabras, como en el índice, y no
 * excluye nada; «de» es una palabra vacía y no se exige.
 */
it('convierte el guion en un separador y no en una exclusión', function (): void {
    $term = FullTextTerm::sanitize('taladro de media-pulgada');

    expect($term?->words)->toBe(['taladro', 'media', 'pulgada'])
        ->and($term?->booleanQuery())->toBe('+taladro* +media* +pulgada*')
        ->and($term?->text)->toBe('taladro de media-pulgada');
});

it('no deja pasar ningún operador del modo booleano', function (string $raw, array $words): void {
    $term = FullTextTerm::sanitize($raw);

    expect($term?->words)->toBe($words);

    // Los únicos signos de la expresión son el `+` y el `*` que pone la clase:
    // cada pieza es `+palabra*`, y la palabra no lleva nada más que letras y cifras.
    $boolean = $term?->booleanQuery();

    foreach ($boolean === null ? [] : explode(' ', $boolean) as $piece) {
        expect(preg_match('/^\+[\p{L}\p{N}\p{M}]+\*$/u', $piece))->toBe(1, $piece);
    }
})->with([
    'el caso del prompt' => ['+"kit" -barato', ['kit', 'barato']],
    // «OR» no es un operador de MySQL y además es palabra vacía del índice.
    'paréntesis y comodín' => ['(taladro* OR sierra)', ['taladro', 'sierra']],
    'comillas sin cerrar' => ['"taladro percutor', ['taladro', 'percutor']],
    'relevancia y negación' => ['>taladro <sierra ~broca', ['taladro', 'sierra', 'broca']],
    'distancia' => ['"taladro sierra" @8', ['taladro', 'sierra', '8']],
    'SQL' => ["kit'; DROP TABLE products; --", ['kit', 'DROP', 'TABLE', 'products']],
]);

/** SP-1 · lo que se queda sin palabras no es un término. */
it('ignora el término de solo signos o solo palabras vacías', function (mixed $raw): void {
    expect(FullTextTerm::sanitize($raw))->toBeNull();
})->with([
    'nada' => [null],
    'vacío' => [''],
    'espacios' => ['   '],
    'solo signos' => ['+-*"()<>~@'],
    'solo palabras vacías' => ['de la con el'],
    'palabras vacías del índice' => ['the with'],
    'un array de la query string' => [['taladro']],
    'un número suelto' => [5],
]);

/** SP-3 · más de cien caracteres se recortan, sin error y sin partir una letra. */
it('recorta a cien caracteres, no a cien bytes', function (): void {
    $term = FullTextTerm::sanitize(str_repeat('ñ', 99).'ábc');

    expect(mb_strlen((string) $term?->text))->toBe(FullTextTerm::MAX_LENGTH)
        ->and($term?->text)->toBe(str_repeat('ñ', 99).'á')
        ->and(mb_check_encoding((string) $term?->text, 'UTF-8'))->toBeTrue();
});

it('normaliza los espacios y sustituye el UTF-8 inválido en vez de fallar', function (): void {
    expect(FullTextTerm::sanitize("  taladro \n\t percutor  ")?->text)->toBe('taladro percutor')
        ->and(FullTextTerm::sanitize("taladro \xC3\x28")?->words)->toBe(['taladro']);
});

/**
 * RB-2 · con una palabra que el índice no guarda —menos de tres caracteres—,
 * la búsqueda entera es `LIKE`: el índice la ignoraría en silencio.
 */
it('manda al LIKE lo que el índice no puede contestar', function (string $raw): void {
    $term = FullTextTerm::sanitize($raw);

    expect($term)->not->toBeNull()
        ->and($term?->booleanQuery())->toBeNull();
})->with([
    'dos letras' => ['tv'],
    'una palabra corta junto a otra larga' => ['cable tv'],
    'más larga que el máximo del índice' => [str_repeat('a', 85)],
]);

it('usa el índice desde tres caracteres, con acentos', function (): void {
    expect(FullTextTerm::sanitize('kit')?->booleanQuery())->toBe('+kit*')
        ->and(FullTextTerm::sanitize('eléctrico')?->booleanQuery())->toBe('+eléctrico*');
});

it('escapa los comodines del LIKE aunque ninguna palabra pueda traerlos', function (): void {
    expect(FullTextTerm::sanitize('100% algodón_fino')?->likePatterns())->toBe(['%100%', '%algodón%', '%fino%']);
});
