<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Payment\ReceiptFile;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\Concerns\RunsInTenantContext;
use App\Models\Receipt;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Throwable;

final class CompressReceiptImage implements ShouldQueue
{
    use Queueable, RunsInTenantContext;

    public const MAX_SIDE = 2000;

    public const QUALITY = 80;

    /**
     * @var array<string, string>
     */
    public const COMPRESSIBLE = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function __construct(
        public readonly int $receiptId,
        public readonly int $organizationId,
    ) {
        $this->onQueue('default');
    }

    public static function compresses(string $mime): bool
    {
        return array_key_exists($mime, self::COMPRESSIBLE);
    }

    public function tenantOrganizationId(): int
    {
        return $this->organizationId;
    }

    /**
     * @throws ModelNotFoundException
     */
    public function handle(): void
    {
        $this->runInTenantContext(function (): void {
            $receipt = Receipt::query()->find($this->receiptId);

            if (! $receipt instanceof Receipt) {
                Log::warning('receipt.compression.missing', $this->logContext());

                return;
            }

            if (! self::compresses($receipt->file_mime)) {
                return;
            }

            $this->compress($receipt);

            try {
                app(UsageCalculator::class)->refresh(app(TenantContext::class)->organization(), UsageMetric::StorageMb);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    private function compress(Receipt $receipt): void
    {
        $disk = Storage::disk(Receipt::DISK);
        $extension = self::COMPRESSIBLE[$receipt->file_mime];
        $original = null;
        $output = null;
        $staged = null;

        try {
            $original = $this->localCopy($disk, $receipt->file_path);
            $dimensions = @getimagesize($original);

            if ($dimensions === false) {
                throw new RuntimeException(sprintf('El archivo %s no tiene una cabecera de imagen legible.', $receipt->file_path));
            }

            [$width, $height] = $dimensions;

            if ($width * $height > ReceiptFile::MAX_PIXELS) {
                Log::warning('receipt.compression.too_many_pixels', $this->logContext() + [
                    'width' => $width,
                    'height' => $height,
                    'max_pixels' => ReceiptFile::MAX_PIXELS,
                ]);

                return;
            }

            if (max($width, $height) <= self::MAX_SIDE) {
                return;
            }

            $output = sys_get_temp_dir().'/'.Str::uuid()->toString().'.'.$extension;

            Image::useImageDriver((string) config('media-library.image_driver', 'gd'))
                ->loadFile($original)
                ->fit(Fit::Max, self::MAX_SIDE, self::MAX_SIDE)
                ->quality(self::QUALITY)
                ->save($output);

            $size = filesize($output);

            if ($size === false || $size === 0) {
                throw new RuntimeException('La recompresión no produjo ningún archivo.');
            }

            $staged = dirname($receipt->file_path).'/.'.Str::uuid()->toString().'.compressing';
            $this->upload($disk, $output, $staged);

            if (! $disk->move($staged, $receipt->file_path)) {
                throw new RuntimeException(sprintf('No se pudo sustituir %s por su versión recomprimida.', $receipt->file_path));
            }

            $staged = null;

            $receipt->forceFill(['file_size' => $size])->save();
        } catch (Throwable $exception) {
            report($exception);

            Log::warning('receipt.compression.failed', $this->logContext() + ['error' => $exception->getMessage()]);
        } finally {
            foreach ([$original, $output] as $temporary) {
                if ($temporary !== null && is_file($temporary)) {
                    @unlink($temporary);
                }
            }

            if ($staged !== null) {
                $this->forget($disk, $staged);
            }
        }
    }

    /**
     * @throws RuntimeException
     */
    private function localCopy(Filesystem $disk, string $path): string
    {
        $source = $disk->readStream($path);

        if (! is_resource($source)) {
            throw new RuntimeException(sprintf('El comprobante %s no está en el disco.', $path));
        }

        $copy = sys_get_temp_dir().'/'.Str::uuid()->toString();
        $target = fopen($copy, 'wb');

        try {
            if ($target === false || stream_copy_to_stream($source, $target) === false) {
                throw new RuntimeException(sprintf('No se pudo copiar %s para recomprimirlo.', $path));
            }
        } finally {
            fclose($source);

            if ($target !== false) {
                fclose($target);
            }
        }

        return $copy;
    }

    /**
     * @throws RuntimeException
     */
    private function upload(Filesystem $disk, string $local, string $path): void
    {
        $stream = fopen($local, 'rb');

        if ($stream === false) {
            throw new RuntimeException(sprintf('No se pudo leer la versión recomprimida de %s.', $path));
        }

        try {
            if (! $disk->writeStream($path, $stream)) {
                throw new RuntimeException(sprintf('El disco no guardó %s.', $path));
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function forget(Filesystem $disk, string $path): void
    {
        try {
            $disk->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return array<string, int>
     */
    private function logContext(): array
    {
        return ['receipt_id' => $this->receiptId, 'organization_id' => $this->organizationId];
    }
}
