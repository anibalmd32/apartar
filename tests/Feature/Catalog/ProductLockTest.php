<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\ProductLock;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El bloqueo de la fila del producto (`REQ-LAY-003` RB-3, RB-7, RB-8).
 *
 * Que el bloqueo **serialice** de verdad solo se ve con dos conexiones, y está
 * en `tests/Feature/Concurrency/ReserveStockConcurrencyTest`, junto con el
 * rechazo fuera de una transacción —que aquí no se puede afirmar, porque
 * `RefreshDatabase` siempre abre una—. Lo de aquí es qué se pide y a quién.
 */
final class ProductLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reads_the_product_with_for_update_through_the_tenant_scope(): void
    {
        $organization = Organization::factory()->create();
        $product = $this->withinOrganization($organization, fn (): Product => Product::factory()->create(['stock' => 7]));

        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $locked = $this->withinOrganization($organization, fn (): Product => ProductLock::acquire((int) $product->getKey()));

        $this->assertSame(7, $locked->stock);
        $this->assertCount(1, $statements);
        $this->assertStringContainsString('for update', $statements[0]);
        $this->assertStringContainsString('`products`.`organization_id` = ?', $statements[0]);
    }

    /**
     * RB-7 · el producto de otro negocio no existe para el bloqueo.
     */
    public function test_the_product_of_another_organization_does_not_exist_for_it(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();
        $neighbours = $this->withinOrganization($theirs, fn (): Product => Product::factory()->create());

        $this->expectException(ModelNotFoundException::class);

        $this->withinOrganization($ours, fn (): Product => ProductLock::acquire((int) $neighbours->getKey()));
    }
}
