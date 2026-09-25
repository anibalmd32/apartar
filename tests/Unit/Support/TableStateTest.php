<?php

declare(strict_types=1);

use App\Domain\Catalog\InventorySort;
use App\Domain\Catalog\ProductStatus;
use App\Http\Controllers\Concerns\ResolvesTableState;
use App\Support\TableState;
use Illuminate\Http\Request;

/**
 * El estado de una tabla server-driven, resuelto desde la query string
 * (`REQ-BOF-045` RB-3, RB-4, RB-5, SP-1, SP-2, SP-3, SP-4).
 */
/**
 * @return TableState<InventorySort>
 */
function tableStateFrom(string $query, bool $sortable = true): TableState
{
    $resolver = new class
    {
        use ResolvesTableState {
            tableState as public;
        }
    };

    $request = Request::create('/tabla?'.$query);

    return $sortable
        ? $resolver->tableState($request, InventorySort::whitelist(), InventorySort::Created, descendingByDefault: true)
        : $resolver->tableState($request);
}

it('arranca en la primera página, de 25, con el orden por defecto', function (): void {
    $state = tableStateFrom('');

    expect($state->page)->toBe(1)
        ->and($state->perPage)->toBe(25)
        ->and($state->sort)->toBe(InventorySort::Created)
        ->and($state->sortParameter())->toBe('-created')
        ->and($state->direction())->toBe('desc');
});

it('lee página, tamaño y orden permitidos', function (): void {
    $state = tableStateFrom('page=3&per_page=100&sort=-price');

    expect($state->page)->toBe(3)
        ->and($state->perPage)->toBe(100)
        ->and($state->sort)->toBe(InventorySort::Price)
        ->and($state->direction())->toBe('desc')
        ->and($state->sortParameter())->toBe('-price');
});

it('cae a 25 con cualquier tamaño fuera de la lista (SP-2)', function (string $perPage): void {
    expect(tableStateFrom('per_page='.$perPage)->perPage)->toBe(25);
})->with(['5000', '0', '-25', '24', 'abc', '25.5', '']);

it('cae a la primera página con una página que no es un entero positivo (SP-3)', function (string $page): void {
    expect(tableStateFrom('page='.$page)->page)->toBe(1);
})->with(['0', '-2', 'abc', '1.5', '']);

it('cae al orden por defecto, y a su dirección, con un orden que no está en la lista (SP-1)', function (string $sort): void {
    $state = tableStateFrom('sort='.urlencode($sort));

    expect($state->sort)->toBe(InventorySort::Created)->and($state->descending)->toBeTrue();
})->with(['password', '-password', 'price_cents', '--name', 'Name', str_repeat('a', 40)]);

it('ignora un orden que llega como array', function (): void {
    expect(tableStateFrom('sort[]=name')->sort)->toBe(InventorySort::Created);
});

it('sirve a una tabla sin orden elegible', function (): void {
    $state = tableStateFrom('sort=name&page=2', sortable: false);

    expect($state->sort)->toBeNull()
        ->and($state->sortParameter())->toBeNull()
        ->and($state->page)->toBe(2);
});

describe('los filtros', function (): void {
    it('devuelve el valor resuelto y lo anota como texto para la URL', function (): void {
        $state = tableStateFrom('');

        $status = $state->filter('status', 'draft', fn (mixed $raw): ?ProductStatus => ProductStatus::tryFrom((string) $raw));
        $flag = $state->filter('low_stock', '1', fn (): bool => true);

        expect($status)->toBe(ProductStatus::Draft)
            ->and($state->filters())->toBe(['status' => 'draft', 'low_stock' => '1'])
            ->and($state->ignored())->toBe([]);
    });

    it('un filtro ausente no se aplica y no es un aviso', function (mixed $raw): void {
        $state = tableStateFrom('');

        expect($state->filter('status', $raw, fn (): string => 'nunca'))->toBeNull()
            ->and($state->filters())->toBe(['status' => null])
            ->and($state->ignored())->toBe([]);
    })->with([[null], [''], [[]]]);

    it('un valor que no se resuelve se ignora y se avisa (SP-4)', function (): void {
        $state = tableStateFrom('');

        expect($state->filter('category', '999', fn (): ?int => null))->toBeNull()
            ->and($state->filters())->toBe(['category' => null])
            ->and($state->ignored())->toBe(['category']);
    });

    it('usa la forma de volver a la URL que se le dé', function (): void {
        $state = tableStateFrom('');

        $state->filter('from', '2026-09-24', fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-24 10:00'), fn (DateTimeImmutable $d): string => $d->format('Y-m-d'));

        expect($state->filters())->toBe(['from' => '2026-09-24']);
    });
});
