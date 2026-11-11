<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Catalog\UpsertCatalogAction;
use App\Actions\Organization\UpdateOrganizationAction;
use App\Domain\Tenancy\OrganizationBrand;
use App\Models\Product;
use App\Support\Media\ImageMetadata;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final class StripUploadedImageMetadata extends Command
{
    protected $signature = 'uploads:strip-metadata
        {--dry-run : Cuenta las imágenes con metadatos sin reescribir nada}';

    protected $description = 'Quita EXIF, GPS y demás metadatos de las fotos de producto, logos y portadas ya subidos';

    /**
     * @var array{checked: int, cleaned: int, failed: int}
     */
    private array $totals = ['checked' => 0, 'cleaned' => 0, 'failed' => 0];

    /**
     * @var list<string>
     */
    private array $errors = [];

    public function handle(): int
    {
        $this->totals = ['checked' => 0, 'cleaned' => 0, 'failed' => 0];
        $this->errors = [];

        Media::query()
            ->where('model_type', (new Product)->getMorphClass())
            ->where('collection_name', Product::IMAGES)
            ->lazyById()
            ->each(function (Media $media): void {
                $this->clean(Storage::disk($media->disk), $media->getPathRelativeToRoot(), function (int $size) use ($media): void {
                    $media->size = $size;
                    $media->saveQuietly();
                });
            });

        $this->cleanDirectory(OrganizationBrand::LOGO_DISK, UpdateOrganizationAction::LOGO_DIRECTORY);
        $this->cleanDirectory(UpsertCatalogAction::COVER_DISK, UpsertCatalogAction::COVER_DIRECTORY);

        $this->table(
            ['Revisadas', $this->option('dry-run') ? 'Con metadatos' : 'Limpiadas', 'Fallidas'],
            [[$this->totals['checked'], $this->totals['cleaned'], $this->totals['failed']]],
        );

        foreach ($this->errors as $error) {
            $this->components->error($error);
        }

        if ($this->errors !== []) {
            return self::FAILURE;
        }

        $this->components->info($this->option('dry-run')
            ? 'Simulación terminada: no se reescribió nada.'
            : 'Listo. Las fotos de producto pesan algo menos: corre subscriptions:recalculate-usage --metric=storage_mb para que el consumo lo refleje.');

        return self::SUCCESS;
    }

    private function cleanDirectory(string $disk, string $directory): void
    {
        $storage = Storage::disk($disk);

        foreach ($storage->allFiles($directory) as $path) {
            $this->clean($storage, $path);
        }
    }

    /**
     * @param  (callable(int): void)|null  $resized
     */
    private function clean(Filesystem $storage, string $path, ?callable $resized = null): void
    {
        $this->totals['checked']++;

        try {
            $bytes = $storage->get($path) ?? throw new RuntimeException('no se pudo leer.');
            $clean = ImageMetadata::strip($bytes);

            if ($clean === $bytes) {
                return;
            }

            $this->totals['cleaned']++;

            if ($this->option('dry-run')) {
                return;
            }

            if (! $storage->put($path, $clean)) {
                throw new RuntimeException('el disco rechazó la versión limpia.');
            }

            if ($resized !== null) {
                $resized(strlen($clean));
            }
        } catch (Throwable $exception) {
            $this->totals['failed']++;
            $this->errors[] = sprintf('%s: %s', $path, $exception->getMessage());
        }
    }
}
