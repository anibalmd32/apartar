<?php

declare(strict_types=1);

use App\Domain\Catalog\SearchMode;
use App\Models\Product;
use App\Support\FullTextTerm;

function searchSql(string $term, SearchMode $mode): string
{
    $sanitized = FullTextTerm::sanitize($term);
    assert($sanitized instanceof FullTextTerm);

    return strtolower(Product::query()->withoutGlobalScopes()->search($sanitized, $mode)->toSql());
}

it('con el índice pregunta al MATCH de las tres columnas del índice', function (): void {
    expect(searchSql('taladro', SearchMode::FullText))
        ->toContain('match (`products`.`name`, `products`.`description`, `products`.`sku`) against (? in boolean mode)')
        ->not->toContain(' like ');
});

it('con LIKE exige cada palabra en el nombre o en el SKU, y no mira la descripción', function (): void {
    $sql = searchSql('taladro percutor', SearchMode::Like);

    expect(substr_count($sql, '(`products`.`name` like ? or `products`.`sku` like ?)'))->toBe(2)
        ->and($sql)->not->toContain('description')
        ->and($sql)->not->toContain('match');
});

it('manda al LIKE el término corto aunque se pida el índice', function (): void {
    expect(searchSql('tv', SearchMode::FullText))->toContain(' like ')->not->toContain('match');
});

it('enlaza el término y no lo escribe en la consulta', function (): void {
    $term = FullTextTerm::sanitize('taladro');
    assert($term instanceof FullTextTerm);

    $query = Product::query()->withoutGlobalScopes()->search($term, SearchMode::FullText);

    expect($query->toSql())->not->toContain('taladro')
        ->and($query->getBindings())->toBe(['+taladro*']);
});
