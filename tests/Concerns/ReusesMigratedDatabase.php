<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait ReusesMigratedDatabase
{
    use RefreshDatabase;

    protected function migrateDatabases(): void
    {
        $fingerprint = $this->migrationsFingerprint();

        if ($this->databaseIsReusable($fingerprint)) {
            return;
        }

        $this->artisan('migrate:fresh', $this->migrateFreshUsing());

        DB::statement('ALTER TABLE migrations COMMENT = '.DB::getPdo()->quote($fingerprint));
    }

    private function migrationsFingerprint(): string
    {
        $files = glob(database_path('migrations/*.php')) ?: [];
        sort($files);

        $context = hash_init('sha1');

        foreach ($files as $file) {
            hash_update($context, basename($file));
            hash_update_file($context, $file);
        }

        return hash_final($context);
    }

    private function databaseIsReusable(string $fingerprint): bool
    {
        if (! Schema::hasTable('migrations')) {
            return false;
        }

        $tables = DB::select(
            "select table_name as name, table_comment as comment from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE'",
        );

        $stored = collect($tables)->firstWhere('name', 'migrations')?->comment;

        if ($stored !== $fingerprint) {
            return false;
        }

        foreach ($tables as $table) {
            if ($table->name === 'migrations') {
                continue;
            }

            if (DB::table($table->name)->exists()) {
                return false;
            }
        }

        return true;
    }
}
