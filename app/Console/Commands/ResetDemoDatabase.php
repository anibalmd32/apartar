<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResetsDatabase;
use Database\Seeders\DevSeeder;
use Illuminate\Console\Command;

final class ResetDemoDatabase extends Command
{
    use ResetsDatabase;

    protected $signature = 'db:reset-demo {--force : No pide confirmación}';

    protected $description = 'Borra todos los datos y archivos y siembra el escenario de prueba con negocios, catálogos y apartados';

    public function handle(): int
    {
        if ($this->laravel->isProduction()) {
            $this->components->error('El escenario de prueba no se siembra en producción. Usa db:reset-empty.');

            return self::FAILURE;
        }

        if (! $this->confirmWipe('Se borrarán TODOS los datos y archivos subidos y se sembrará el escenario de prueba. ¿Continuar?')) {
            return self::FAILURE;
        }

        $this->recreateSchema();
        $this->wipeStorage();
        $this->flushCache();

        $this->call('db:seed', ['--class' => DevSeeder::class, '--force' => true]);

        return self::SUCCESS;
    }
}
