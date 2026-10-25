<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\InsufficientStockException;
use App\Models\Product;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class InsufficientStockExceptionTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function availabilities(): array
    {
        return [
            'ninguna' => [0, 'Ya no quedan unidades disponibles de este producto.'],
            'una' => [1, 'Solo queda 1 unidad disponible de este producto. Reduce la cantidad para apartar.'],
            'varias' => [3, 'Solo quedan 3 unidades disponibles de este producto. Reduce la cantidad para apartar.'],
        ];
    }

    #[DataProvider('availabilities')]
    public function test_it_tells_how_many_units_are_left(int $available, string $message): void
    {
        $product = (new Product)->forceFill(['stock' => $available]);

        $exception = InsufficientStockException::for($product, $available + 1);

        $this->assertSame($message, $exception->getMessage());
        $this->assertSame($available, $exception->available);
        $this->assertSame($available + 1, $exception->requested);
        $this->assertSame($product, $exception->product);
        $this->assertSame(422, InsufficientStockException::STATUS);
    }
}
