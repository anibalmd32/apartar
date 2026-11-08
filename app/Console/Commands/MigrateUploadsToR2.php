<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Throwable;

final class MigrateUploadsToR2 extends Command
{
    protected $signature = 'uploads:migrate-to-r2
        {--disk=* : Solo estos discos (public, receipts, mail, exports)}
        {--dry-run : Cuenta lo que se copiaría sin subir nada}
        {--force : Vuelve a subir aunque el archivo ya esté en R2 con el mismo tamaño}';

    protected $description = 'Copia a Cloudflare R2 los archivos subidos que siguen en el disco local del contenedor';

    /**
     * @var list<string>
     */
    private array $errors = [];

    public function handle(): int
    {
        $uploads = $this->selectedUploads();

        if ($uploads === null) {
            return self::FAILURE;
        }

        $rows = [];

        foreach ($uploads as $disk => $root) {
            $target = Storage::disk($disk);

            if ($this->pointsTo($target, $root)) {
                $this->components->error(sprintf(
                    'El disco «%s» todavía guarda en %s. Pon UPLOADS_STORAGE=r2 y las variables R2_* antes de migrar.',
                    $disk,
                    $root,
                ));

                return self::FAILURE;
            }

            $rows[] = [$disk, ...$this->migrate($disk, $root, $target)];
        }

        $this->table(['Disco', $this->option('dry-run') ? 'Por copiar' : 'Copiados', 'Ya estaban', 'Fallidos'], $rows);

        foreach ($this->errors as $error) {
            $this->components->error($error);
        }

        if ($this->errors !== []) {
            return self::FAILURE;
        }

        $this->components->info($this->option('dry-run')
            ? 'Simulación terminada: no se subió nada.'
            : 'Migración terminada. Las rutas de la base no cambian: son relativas al disco.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>|null
     */
    private function selectedUploads(): ?array
    {
        $uploads = (array) config('filesystems.uploads');
        $requested = array_map(strval(...), (array) $this->option('disk'));

        if ($requested === []) {
            return array_map(strval(...), $uploads);
        }

        $unknown = array_diff($requested, array_keys($uploads));

        if ($unknown !== []) {
            $this->components->error(sprintf('No hay disco de archivos subidos llamado: %s.', implode(', ', $unknown)));

            return null;
        }

        return array_map(strval(...), array_intersect_key($uploads, array_flip($requested)));
    }

    private function pointsTo(Filesystem $target, string $root): bool
    {
        if (! $target instanceof FilesystemAdapter || ! $target->getAdapter() instanceof LocalFilesystemAdapter) {
            return false;
        }

        $current = realpath($target->path(''));

        return $current !== false && $current === realpath($root);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function migrate(string $disk, string $root, Filesystem $target): array
    {
        if (! is_dir($root)) {
            return [0, 0, 0];
        }

        $source = Storage::build(['driver' => 'local', 'root' => $root, 'throw' => true]);
        $files = array_values(array_filter($source->allFiles(), fn (string $path): bool => ! str_starts_with(basename($path), '.')));
        [$copied, $present, $failed] = [0, 0, 0];

        $this->components->twoColumnDetail($disk, sprintf('%d archivos', count($files)));
        $bar = $this->output->createProgressBar(count($files));

        foreach ($files as $path) {
            $bar->advance();

            try {
                if (! $this->option('force') && $target->exists($path) && $target->size($path) === $source->size($path)) {
                    $present++;

                    continue;
                }

                if (! $this->option('dry-run')) {
                    $this->copy($source, $target, $path);
                }

                $copied++;
            } catch (Throwable $exception) {
                $failed++;
                $this->errors[] = sprintf('%s/%s: %s', $disk, $path, $exception->getMessage());
            }
        }

        $bar->finish();
        $this->newLine();

        return [$copied, $present, $failed];
    }

    /**
     * @throws RuntimeException
     */
    private function copy(Filesystem $source, Filesystem $target, string $path): void
    {
        $stream = $source->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException('no se pudo leer el archivo local.');
        }

        try {
            if ($target->writeStream($path, $stream) === false) {
                throw new RuntimeException('R2 rechazó la subida.');
            }
        } finally {
            $this->close($stream);
        }

        if ($target->size($path) !== $source->size($path)) {
            throw new RuntimeException('el archivo en R2 no tiene el mismo tamaño que el local.');
        }
    }

    private function close(mixed $stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}
