<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResetsDatabase;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlatformAdminSeeder;
use Illuminate\Console\Command;
use RuntimeException;

final class ResetEmptyDatabase extends Command
{
    use ResetsDatabase;

    protected $signature = 'db:reset-empty {--force : No pide confirmación}';

    protected $description = 'Borra todos los datos y archivos y deja la base lista para producción: solo planes, tasa inicial y operador de plataforma';

    public function handle(): int
    {
        $question = $this->laravel->isProduction()
            ? 'Estás en PRODUCCIÓN. Se borrarán TODOS los negocios, clientes, pagos y archivos subidos. ¿Continuar?'
            : 'Se borrarán TODOS los datos y archivos subidos y la base quedará vacía. ¿Continuar?';

        if (! $this->confirmWipe($question)) {
            return self::FAILURE;
        }

        $this->recreateSchema();
        $this->wipeStorage();
        $this->flushCache();

        $this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        try {
            $this->callSilently('db:seed', ['--class' => PlatformAdminSeeder::class, '--force' => true]);
            $this->components->info('Operador de plataforma creado con PLATFORM_ADMIN_EMAIL; tendrá que cambiar la contraseña al entrar.');
        } catch (RuntimeException $exception) {
            $this->components->warn($exception->getMessage().' Revisa PLATFORM_ADMIN_NAME, PLATFORM_ADMIN_EMAIL y PLATFORM_ADMIN_PASSWORD en .env y ejecuta: php artisan db:seed --class='.PlatformAdminSeeder::class);
        }

        return self::SUCCESS;
    }
}
