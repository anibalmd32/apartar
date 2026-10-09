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

/**
 * Recomprime la imagen de un comprobante, en cola (`REQ-PAY-002` RB-6).
 *
 * Calidad 80 y el lado mayor a 2000 px como mucho: lo que el negocio necesita
 * para leer una referencia en una captura, y no los doce megapíxeles de la
 * foto que el cliente le hizo a la pantalla de su banco. Lo despacha
 * `SubmitReceiptAction` después del commit.
 *
 * ## Solo JPEG y PNG
 *
 * El WebP **no**, porque la extensión GD del contenedor no lo codifica
 * —`imagewebp()` no existe ahí— y el job fallaría siempre. El PDF tampoco: no
 * es una imagen. Los dos se guardan tal cual llegaron.
 *
 * ## El original se conserva hasta que haya algo mejor (SP-7)
 *
 * La imagen nueva se escribe primero en un temporal; solo si todo salió bien
 * se sube al disco junto al original y se **mueve** encima de él, que en un
 * disco local es un `rename` y no deja ningún instante con el archivo a
 * medias. Si algo falla en cualquier punto, el original sigue donde estaba, se
 * informa con `report()` y se registra el aviso. No se relanza: reintentar la
 * misma imagen da el mismo fallo, y el comprobante ya es perfectamente
 * revisable sin recomprimir.
 *
 * ## Antes de abrirla, se cuentan sus píxeles (SP-8)
 *
 * Una imagen de 50.000 × 50.000 de un solo color pesa unos kilobytes
 * comprimida y pediría casi diez gigas al descomprimirla. `getimagesize()` lee
 * solo la cabecera, así que se mira ahí y, si pasa de
 * `ReceiptFile::MAX_PIXELS`, no se abre: se registra y se deja como está.
 *
 * ## Lo que no cambia
 *
 * `file_hash`: es el del archivo **tal cual se subió** (`REQ-PAY-003` RB-7), y
 * es lo que reconoce la misma captura subida otra vez. Recomprimir cambia los
 * bytes, no el comprobante. `file_size` sí se actualiza, porque es lo que pesa
 * ahora en disco y lo que mide la cuota (`REQ-SUB-003` SP-4).
 *
 * Usa `RunsInTenantContext`: el comprobante es una tabla del tenant y el job
 * no hereda la organización de quien lo encoló.
 */
final class CompressReceiptImage implements ShouldQueue
{
    use Queueable, RunsInTenantContext;

    /** El lado mayor, en píxeles (RB-6). */
    public const MAX_SIDE = 2000;

    /** La calidad de la recompresión (RB-6). */
    public const QUALITY = 80;

    /**
     * Los tipos que sabe recomprimir, con la extensión con que los escribe.
     *
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
        // `REQ-INF-003`: las conversiones de imagen van a `default`.
        $this->onQueue('default');
    }

    /**
     * Si un archivo de ese tipo real se recomprime.
     */
    public static function compresses(string $mime): bool
    {
        return array_key_exists($mime, self::COMPRESSIBLE);
    }

    public function tenantOrganizationId(): int
    {
        return $this->organizationId;
    }

    /**
     * @throws ModelNotFoundException si el payload trae una organización que ya no existe
     */
    public function handle(): void
    {
        $this->runInTenantContext(function (): void {
            $receipt = Receipt::query()->find($this->receiptId);

            if (! $receipt instanceof Receipt) {
                // Se borró, o el payload trae un comprobante de otra
                // organización: para el global scope son lo mismo.
                Log::warning('receipt.compression.missing', $this->logContext());

                return;
            }

            if (! self::compresses($receipt->file_mime)) {
                return;
            }

            $this->compress($receipt);

            // `REQ-SUB-003` SP-4 · la recompresión baja `storage_mb`. Un fallo
            // al medir no deshace la recompresión: la noche lo cuadra.
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
                // Ya cabe. Volver a codificar una captura que ya está
                // comprimida solo pierde calidad, y a menudo pesa más.
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
     * Una copia local del original para GD y `getimagesize()`, que leen
     * rutas y no discos.
     *
     * @throws RuntimeException si el original no está o no se puede leer
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
     * @throws RuntimeException si el disco no lo escribió
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
     * Con qué se identifica el comprobante en el log: sus ids. Nada de lo que
     * declaró el cliente —banco, referencia, importe— tiene por qué acabar
     * ahí.
     *
     * @return array<string, int>
     */
    private function logContext(): array
    {
        return ['receipt_id' => $this->receiptId, 'organization_id' => $this->organizationId];
    }
}
