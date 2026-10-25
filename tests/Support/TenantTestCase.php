<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

abstract class TenantTestCase extends TestCase
{
    use ReusesMigratedDatabase;

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
