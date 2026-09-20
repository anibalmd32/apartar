<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\ProductNotAvailableException;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Apartar algo que ya no se puede apartar (`REQ-CAT-007` SP-2).
 *
 * **Lo que se prueba es la guarda, no el recorrido.** `CreateLayawayAction` es
 * de `T-044` y aquí no se inventa un apartado para tener dónde llamarla: lo
 * que `T-030` deja escrito es el mecanismo —qué estados admiten apartado y qué
 * se responde cuando no—, que es la misma línea que aquella acción llamará.
 *
 * El caso real es el formulario que ya estaba abierto: el negocio archiva el
 * producto mientras un cliente tenía la ficha en pantalla, y el envío llega
 * después. Por eso la comprobación no puede vivir en la pantalla —se pintó
 * cuando la respuesta todavía era «sí»— y por eso es `422` y no `404`: el
 * producto existe y el cliente lo estaba viendo hace un minuto.
 */
final class ProductNotAvailableExceptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->actingAsOrganization(Organization::factory()->create());
    }

    /**
     * SP-2 · sobre un archivado no se aparta **nunca**, aunque el formulario
     * ya estuviera abierto.
     */
    public function test_an_archived_product_cannot_be_reserved(): void
    {
        $product = Product::factory()->archived()->create(['name' => 'Taladro Bosch']);

        try {
            ProductNotAvailableException::guard($product);

            $this->fail('Apartar un archivado tenía que rechazarse.');
        } catch (ProductNotAvailableException $exception) {
            $this->assertTrue($exception->product->is($product));
            $this->assertStringContainsString('Taladro Bosch', $exception->getMessage());
            $this->assertStringContainsString('ya no está disponible', $exception->getMessage());
            $this->assertSame(422, ProductNotAvailableException::STATUS);
        }
    }

    /**
     * Y un borrador tampoco, por la misma puerta: su URL ni siquiera es
     * alcanzable (RB-1), así que llegar aquí con uno es manipulación de la
     * petición.
     */
    public function test_a_draft_product_cannot_be_reserved_either(): void
    {
        $this->expectException(ProductNotAvailableException::class);

        ProductNotAvailableException::guard(Product::factory()->create());
    }

    /**
     * **El mensaje no distingue borrador de archivado**, a propósito: decirlo
     * le contaría al cliente en qué punto de su inventario anda el negocio, y
     * desde su lado son lo mismo — lo que tenía delante ya no está a la venta.
     */
    public function test_the_message_does_not_leak_which_state_it_was_in(): void
    {
        $draft = Product::factory()->create(['name' => 'Sierra']);
        $archived = Product::factory()->archived()->create(['name' => 'Sierra']);

        $this->assertSame(
            ProductNotAvailableException::for($draft)->getMessage(),
            ProductNotAvailableException::for($archived)->getMessage(),
        );
    }

    /**
     * **RB-4 · agotado no es lo mismo que retirado.** Un producto sin
     * existencias sigue `published` y sigue en el catálogo con su sello; quien
     * lo rechaza es `CreateLayawayAction` por otro motivo (`REQ-LAY-003`
     * SP-1). Confundir las dos puertas haría que «se acabó» se leyera como «ya
     * no se vende».
     */
    public function test_an_out_of_stock_published_product_passes_this_guard(): void
    {
        $product = Product::factory()->published()->outOfStock()->create();

        ProductNotAvailableException::guard($product);

        $this->assertTrue($product->status->canBeReserved());
    }

    /**
     * Y la publicación programada también pasa: el estado ya es `published` y
     * lo que la mantiene invisible es la consulta (RB-5). Un cliente no puede
     * tener abierta la ficha de algo que todavía no se lista, así que esta
     * guarda no es la que decide eso.
     */
    public function test_a_published_product_passes_this_guard(): void
    {
        $scheduled = Product::factory()->scheduled()->create();

        ProductNotAvailableException::guard(Product::factory()->published()->create());
        ProductNotAvailableException::guard($scheduled);

        $this->assertTrue($scheduled->status->canBeReserved());
    }
}
