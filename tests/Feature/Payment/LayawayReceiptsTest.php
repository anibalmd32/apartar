<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\LayawayReceipts;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * El punto de extensión de `REQ-LAY-006` RB-2 y su relevo.
 *
 * Es la forma de `RecordedReceiptsTest`: la respuesta de hoy es honesta —no
 * hay tabla, no hay comprobantes— y **el día que llegue la tabla deja de
 * responder** hasta que `T-054` rellene el cuerpo.
 */
final class LayawayReceiptsTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    public function test_no_layaway_has_receipts_while_the_table_does_not_exist(): void
    {
        $organization = Organization::factory()->create();
        $layaway = $this->layawayIn($organization);

        $this->assertFalse(
            Schema::hasTable('receipts'),
            '`receipts` ya existe: `LayawayReceipts::holdExpiration()` tiene que consultarla (`T-054`).',
        );

        $this->assertFalse((new LayawayReceipts)->holdExpiration($layaway));
    }

    /**
     * Se finge la tabla en vez de crearla: un `CREATE TABLE` a mitad de un
     * test confirma implícitamente y rompe `RefreshDatabase`.
     */
    public function test_it_throws_once_the_table_exists_and_nobody_filled_the_body(): void
    {
        $organization = Organization::factory()->create();
        $layaway = $this->layawayIn($organization);

        Schema::shouldReceive('hasTable')->with('receipts')->andReturnTrue();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/T-054/');

        (new LayawayReceipts)->holdExpiration($layaway);
    }
}
