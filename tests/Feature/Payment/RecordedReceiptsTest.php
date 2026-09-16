<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\RecordedReceipts;
use App\Models\Organization;
use App\Models\PaymentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * El punto de extensión de RB-6 (`REQ-ORG-007`) y su relevo.
 *
 * `UpsertPaymentAccountAction` ya pregunta, ya degrada el borrado y ya tiene su
 * test —con esta clase sustituida en el contenedor—. Lo que se comprueba aquí
 * es la otra mitad: que **la respuesta de hoy es honesta y no un `TODO`
 * disfrazado**, y que **el día que llegue la tabla deja de responder**.
 *
 * Es la lección que dejó pagada `T-014`. `PublishedCatalogs::exist()` nació
 * respondiendo `false` mientras su tabla no existiera, y esa forma tenía una
 * ventana fatal: `T-024` crea `catalogs` ocho tareas antes de que `T-032`
 * rellene el cuerpo, y en ese hueco la regla quedaba apagada sin un solo test
 * en rojo. Aquí la guarda va al revés desde el primer día.
 */
final class RecordedReceiptsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->actingAsOrganization(Organization::factory()->create());
    }

    public function test_no_account_has_receipts_while_the_table_does_not_exist(): void
    {
        $this->assertFalse(
            Schema::hasTable('receipts'),
            '`receipts` ya existe: `RecordedReceipts::existFor()` tiene que consultarla (`T-054`).',
        );

        $this->assertFalse((new RecordedReceipts)->existFor(PaymentAccount::factory()->create()));
    }

    /**
     * **Y falla ruidosamente cuando la tabla llegue.**
     *
     * `T-054` crea `receipts` y es quien tiene que rellenar el cuerpo — no una
     * tarea posterior, que es justamente el error que esta guarda impide
     * repetir. Mientras no lo haga, la clase lanza en vez de responder que no,
     * y el mensaje dice qué hay que escribir y por qué.
     *
     * Se **finge** que la tabla existe en vez de crearla: un `CREATE TABLE` a
     * mitad de un test provoca en MySQL un *commit* implícito que se lleva por
     * delante el aislamiento de `RefreshDatabase`. Es el mismo motivo por el
     * que `tenancy:audit` audita archivos y no el esquema migrado.
     */
    public function test_it_throws_once_the_table_exists_and_nobody_filled_the_body(): void
    {
        $account = PaymentAccount::factory()->create();

        Schema::shouldReceive('hasTable')->with('receipts')->andReturnTrue();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/T-054/');

        (new RecordedReceipts)->existFor($account);
    }

    /**
     * Y se puede sustituir, que es lo que permite ejercitar la degradación del
     * borrado antes de que exista el modelo. Si la clase fuera `final`, RB-6 no
     * se podría probar hasta H4.
     */
    public function test_it_can_be_replaced_to_exercise_the_degraded_delete(): void
    {
        $withReceipts = new class extends RecordedReceipts
        {
            public function existFor(PaymentAccount $account): bool
            {
                return true;
            }
        };

        $this->assertTrue($withReceipts->existFor(PaymentAccount::factory()->create()));
    }
}
