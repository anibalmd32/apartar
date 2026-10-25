<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

trait ResetsDatabase
{
    /**
     * @var list<string>
     */
    private array $wipedDisks = ['public', 'local'];

    private function confirmWipe(string $question): bool
    {
        if ((bool) $this->option('force')) {
            return true;
        }

        return $this->confirm($question);
    }

    private function recreateSchema(): void
    {
        $this->components->task('Recreando el esquema', function (): void {
            $this->callSilently('migrate:fresh', ['--force' => true]);
        });
    }

    private function wipeStorage(): void
    {
        $this->components->task('Vaciando los archivos subidos', function (): void {
            foreach ($this->wipedDisks as $name) {
                $disk = Storage::disk($name);

                foreach ($disk->directories() as $directory) {
                    $disk->deleteDirectory($directory);
                }

                foreach ($disk->files() as $file) {
                    if (basename($file) !== '.gitignore') {
                        $disk->delete($file);
                    }
                }
            }
        });
    }

    private function flushCache(): void
    {
        try {
            Cache::flush();
        } catch (Throwable $exception) {
            $this->components->warn('No se pudo vaciar la caché: '.$exception->getMessage());
        }
    }
}
