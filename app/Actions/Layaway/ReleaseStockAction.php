<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Catalog\ProductLock;
use App\Models\Layaway;
use App\Models\Product;
use App\Support\ModelState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ReleaseStockAction
{
    /**
     * @throws LogicException
     */
    public function handle(Layaway $layaway): void
    {
        DB::transaction(function () use ($layaway): void {
            $product = $this->lockProductOf($layaway);

            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->stock_released_at !== null) {
                return;
            }

            if (! $locked->status->releasesStock()) {
                throw new LogicException(sprintf(
                    'El apartado %d está en «%s» y ese estado no devuelve stock: solo expirar y '
                    .'cancelar lo hacen. Completar significa que la unidad se entregó.',
                    $locked->getKey(),
                    $locked->status->value,
                ));
            }

            $quantity = (int) $locked->getAttribute('quantity');
            $absorbed = min($product->stock_shortfall, $quantity);

            Product::query()->whereKey($product->getKey())->toBase()->update([
                'stock' => $product->stock + $quantity - $absorbed,
                'stock_shortfall' => $product->stock_shortfall - $absorbed,
            ]);

            $releasedAt = CarbonImmutable::now();

            Layaway::query()->whereKey($locked->getKey())->update(['stock_released_at' => $releasedAt]);

            ModelState::adopt($layaway, ['stock_released_at' => $releasedAt->format('Y-m-d H:i:s')] + $layaway->getAttributes());
        });
    }

    public function lockProductOf(Layaway $layaway): Product
    {
        return ProductLock::acquire($this->productIdOf($layaway));
    }

    private function productIdOf(Layaway $layaway): int
    {
        $productId = $layaway->getAttribute('product_id')
            ?? Layaway::query()->whereKey($layaway->getKey())->value('product_id');

        if (! is_numeric($productId)) {
            throw new LogicException("El apartado {$layaway->getKey()} no tiene producto.");
        }

        return (int) $productId;
    }
}
