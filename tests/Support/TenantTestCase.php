<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Caso base de los tests que ejercitan `BelongsToOrganization`.
 *
 * Aporta la tabla de `TenantTestRecord`, y el momento en que la crea es lo
 * único interesante de esta clase: **antes de abrir la transacción del test**.
 *
 * En MySQL, un `CREATE TABLE` provoca un *commit* implícito. Crear la tabla
 * dentro del `setUp` normal cerraría la transacción que `RefreshDatabase` abre
 * para aislar cada test, y a partir de ahí las filas dejarían de revertirse:
 * los tests empezarían a verse entre sí y el fallo aparecería lejos de su
 * causa. Por eso se interpone en `refreshTestDatabase()`, que es el único
 * punto entre la migración y el `BEGIN`.
 */
abstract class TenantTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Réplica de `RefreshDatabase::refreshTestDatabase()` con la creación de
     * la tabla de prueba intercalada donde tiene que ir.
     */
    protected function refreshTestDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->migrateDatabases();

            $this->app->make(Kernel::class)->setArtisan(null);

            RefreshDatabaseState::$migrated = true;
        }

        $this->createTenantTestTable();

        $this->beginDatabaseTransaction();
    }

    /**
     * Tabla del modelo de prueba: lo mínimo que necesita una tabla del tenant.
     *
     * El índice único es `(organization_id, name)` y no `(name)` porque esa es
     * la regla de `REQ-ORG-002` RB-3: **todo índice único de negocio incluye
     * `organization_id`**. Dos organizaciones pueden tener una fila con el
     * mismo nombre sin saber la una de la otra.
     */
    private function createTenantTestTable(): void
    {
        if (Schema::hasTable('tenant_test_records')) {
            return;
        }

        Schema::create('tenant_test_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });
    }
}
